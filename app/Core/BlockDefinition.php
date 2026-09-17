<?php

namespace App\Core;

use RuntimeException;

/**
 * The block contract (SPEC §5.3): what a block.php must contain, checked once.
 *
 * Split out of Blocks, which reached the 300-line limit when a section learned to draw a
 * background picture. The seam is a real one and not a line count: everything here is
 * static, touches no registry state, and runs exactly once per block at discovery — while
 * what remains in Blocks is a live registry that answers questions and renders. Rendering
 * could not have moved instead: it needs the definitions and the template directory, so
 * extracting it would have meant inventing a collaborator to carry them.
 *
 * Every problem is fatal and names the block and the key. A malformed definition throws at
 * boot, never silently at render.
 */
final class BlockDefinition
{
    /** The closed set of field types in SPEC §5.3. */
    public const FIELD_TYPES = ['text', 'textarea', 'richtext', 'media', 'media_multi', 'link', 'select', 'toggle', 'number', 'repeater'];

    /** The subset implemented so far. The rest arrive when a block needs them. */
    public const SUPPORTED_FIELD_TYPES = ['text', 'textarea', 'richtext', 'media', 'link', 'select'];

    public const NAME = '~^[a-z][a-z0-9_]*$~';
    public const SLUG = '~^[a-z][a-z0-9_-]*$~';

    private const KEYS = ['type', 'icon', 'version', 'fields', 'layouts', 'defaults'];
    private const FIELD_KEYS = ['type', 'required', 'translatable', 'options'];

    /** Names the page editor uses for its own inputs inside blocks[n]. */
    private const RESERVED_FIELD_NAMES = ['id', 'type'];

    /**
     * Checks one definition against SPEC §5.3 and returns it with the optional field
     * flags filled in.
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

    public static function fail(string $type, string $message): never
    {
        throw new RuntimeException("Block {$type}: {$message}");
    }
}
