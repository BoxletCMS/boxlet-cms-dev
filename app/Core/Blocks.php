<?php

namespace App\Core;

use App\Modules\Design\SectionStyle;
use RuntimeException;
use Throwable;

/**
 * Block registry. Discovers app/Blocks/{type}/block.php, validates every definition
 * against the contract in SPEC §5.3 and renders block templates. A malformed
 * definition throws at boot, never silently at render.
 */
final class Blocks
{
    /** The closed set of field types in SPEC §5.3. */
    public const FIELD_TYPES = ['text', 'textarea', 'richtext', 'media', 'media_multi', 'link', 'select', 'toggle', 'number', 'repeater'];

    /** The subset implemented so far. The rest arrive when a block needs them. */
    public const SUPPORTED_FIELD_TYPES = ['text', 'textarea', 'richtext', 'media', 'link', 'select'];

    private const KEYS = ['type', 'icon', 'version', 'fields', 'layouts', 'defaults'];
    private const FIELD_KEYS = ['type', 'required', 'translatable', 'options'];
    private const NAME = '~^[a-z][a-z0-9_]*$~';
    private const SLUG = '~^[a-z][a-z0-9_-]*$~';

    /** Names the page editor uses for its own inputs inside blocks[n]. */
    private const RESERVED_FIELD_NAMES = ['id', 'type'];

    /**
     * @param array<string, array<string, mixed>> $definitions validated, keyed by type
     */
    private function __construct(private readonly array $definitions, private readonly string $directory)
    {
    }

    public static function discover(string $directory): self
    {
        $definitions = [];
        foreach (glob($directory . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $type = basename($dir);
            foreach (['block.php', 'template.php'] as $file) {
                if (!is_file($dir . '/' . $file)) {
                    self::fail($type, "missing {$file}");
                }
            }
            $definitions[$type] = self::validate($type, require $dir . '/block.php');
        }
        ksort($definitions);

        return new self($definitions, $directory);
    }

    /**
     * Checks one definition against SPEC §5.3 and returns it with the optional field
     * flags filled in. Every problem is fatal and names the block and the key.
     *
     * @return array<string, mixed>
     */
    public static function validate(string $type, mixed $definition): array
    {
        if (!is_array($definition)) {
            self::fail($type, 'block.php must return an array');
        }
        foreach (self::KEYS as $key) {
            if (!array_key_exists($key, $definition)) {
                self::fail($type, "missing key '{$key}'");
            }
        }
        foreach (array_keys($definition) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                self::fail($type, "unknown key '{$key}'");
            }
        }
        if (!preg_match(self::NAME, $type) || $definition['type'] !== $type) {
            self::fail($type, "'type' must equal the directory name and match [a-z][a-z0-9_]*");
        }
        if (!is_string($definition['icon']) || trim($definition['icon']) === '') {
            self::fail($type, "'icon' must be a non-empty string");
        }
        if (!is_int($definition['version']) || $definition['version'] < 1) {
            self::fail($type, "'version' must be an integer of at least 1");
        }

        if (!is_array($definition['fields']) || $definition['fields'] === []) {
            self::fail($type, "'fields' must be a non-empty array");
        }
        $fields = [];
        foreach ($definition['fields'] as $name => $field) {
            $fields[(string) $name] = self::validateField($type, $name, $field);
        }

        $layouts = $definition['layouts'];
        if (!is_array($layouts) || $layouts === [] || !array_is_list($layouts)) {
            self::fail($type, "'layouts' must be a non-empty list");
        }
        foreach ($layouts as $layout) {
            if (!is_string($layout) || !preg_match(self::SLUG, $layout)) {
                self::fail($type, "layout names must match [a-z][a-z0-9_-]*");
            }
        }
        if (count(array_unique($layouts)) !== count($layouts)) {
            self::fail($type, "'layouts' contains a duplicate");
        }
        $defaults = $definition['defaults'];
        if (!is_array($defaults) || array_keys($defaults) !== ['layout'] || !in_array($defaults['layout'], $layouts, true)) {
            self::fail($type, "'defaults' must be ['layout' => one of 'layouts']");
        }

        return [
            'type' => $type,
            'icon' => $definition['icon'],
            'version' => $definition['version'],
            'fields' => $fields,
            'layouts' => $layouts,
            'defaults' => $defaults,
        ];
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys($this->definitions);
    }

    public function has(string $type): bool
    {
        return isset($this->definitions[$type]);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $type): array
    {
        if (!isset($this->definitions[$type])) {
            throw new RuntimeException("Unknown block type: {$type}");
        }

        return $this->definitions[$type];
    }

    /**
     * $layout if the block still declares it, otherwise the block's default. A definition
     * can change under an existing page; a layout it dropped must not break rendering.
     */
    public function layout(string $type, mixed $layout): string
    {
        $definition = $this->get($type);

        return is_string($layout) && in_array($layout, $definition['layouts'], true)
            ? $layout
            : (string) $definition['defaults']['layout'];
    }

    /**
     * Every field of the block present, with stored values of the wrong shape replaced by
     * the field's empty value, so templates never check whether a key exists.
     *
     * @param array<mixed> $content
     * @return array<string, mixed>
     */
    public function normalize(string $type, array $content): array
    {
        $normalized = [];
        foreach ($this->get($type)['fields'] as $name => $field) {
            $value = $content[$name] ?? null;
            $normalized[$name] = match ($field['type']) {
                'media' => is_int($value) && $value > 0 ? $value : null,
                'link' => [
                    'label' => is_array($value) && is_string($value['label'] ?? null) ? $value['label'] : '',
                    'url' => is_array($value) && is_string($value['url'] ?? null) ? $value['url'] : '',
                ],
                'select' => is_string($value) && in_array($value, $field['options'], true) ? $value : $field['options'][0],
                default => is_string($value) ? $value : '',
            };
        }

        return $normalized;
    }

    /**
     * Renders one block inside its section wrapper. Layers 2 and 3 are class names on
     * the wrapper; nothing is inlined as a style attribute (SPEC §5.4).
     *
     * @param array<mixed> $content stored content_json
     * @param array<mixed> $style   stored style_json
     * @param string       $layout  stored layout; one the block no longer declares renders as its default
     */
    public function render(string $type, array $content, array $style = [], string $layout = ''): string
    {
        $layout = $this->layout($type, $layout);
        $template = $this->directory . '/' . $type . '/template.php';
        $include = static function (string $__template, array $content, array $style, string $layout): void {
            require $__template;
        };

        $style = SectionStyle::normalize($style);
        ob_start();
        try {
            $include($template, $this->normalize($type, $content), $style, $layout);
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        $inner = (string) ob_get_clean();
        $classes = implode(' ', array_merge(['block', 'block-' . $type, 'layout-' . $layout], SectionStyle::classes($style)));

        return '<section class="' . e($classes) . "\">\n<div class=\"container\">\n" . $inner . "</div>\n</section>\n";
    }

    /**
     * @return array<string, mixed>
     */
    private static function validateField(string $type, int|string $name, mixed $field): array
    {
        $at = "field '{$name}'";
        if (!is_string($name) || !preg_match(self::NAME, $name)) {
            self::fail($type, "{$at}: field names must match [a-z][a-z0-9_]*");
        }
        if (in_array($name, self::RESERVED_FIELD_NAMES, true)) {
            self::fail($type, "{$at}: the name is reserved by the page editor");
        }
        if (!is_array($field)) {
            self::fail($type, "{$at}: must be an array");
        }
        foreach (array_keys($field) as $key) {
            if (!in_array($key, self::FIELD_KEYS, true)) {
                self::fail($type, "{$at}: unknown key '{$key}'");
            }
        }
        $fieldType = $field['type'] ?? null;
        if (!is_string($fieldType) || !in_array($fieldType, self::FIELD_TYPES, true)) {
            self::fail($type, "{$at}: 'type' must be one of " . implode(', ', self::FIELD_TYPES));
        }
        if (!in_array($fieldType, self::SUPPORTED_FIELD_TYPES, true)) {
            self::fail($type, "{$at}: field type '{$fieldType}' is in the closed set but not implemented yet");
        }
        foreach (['required', 'translatable'] as $flag) {
            if (array_key_exists($flag, $field) && !is_bool($field[$flag])) {
                self::fail($type, "{$at}: '{$flag}' must be true or false");
            }
        }
        $normalized = [
            'type' => $fieldType,
            'required' => $field['required'] ?? false,
            'translatable' => $field['translatable'] ?? false,
        ];

        if ($fieldType === 'select') {
            $options = $field['options'] ?? null;
            if (!is_array($options) || $options === [] || !array_is_list($options)) {
                self::fail($type, "{$at}: a select needs 'options', a non-empty list of values");
            }
            foreach ($options as $option) {
                if (!is_string($option) || !preg_match(self::SLUG, $option)) {
                    self::fail($type, "{$at}: option values must match [a-z][a-z0-9_-]*");
                }
            }
            $normalized['options'] = $options;
        } elseif (array_key_exists('options', $field)) {
            self::fail($type, "{$at}: only select fields take 'options'");
        }

        return $normalized;
    }

    private static function fail(string $type, string $message): never
    {
        throw new RuntimeException("Block {$type}: {$message}");
    }
}
