<?php

namespace App\Core;

/**
 * A block's OPTIONS (PLAN.md D-166): how it is presented rather than what it says — a hero's
 * height, how many cards stand in a row, the shape of a gallery's pictures.
 *
 * NOT CONTENT, AND THAT IS THE WHOLE DISTINCTION. Content is the owner's words and pictures,
 * translated and never touched by a character. An option is a design question about one
 * block, so it has the rule every other design question has (D-164, D-165): '' until the
 * owner sets it, and '' is the character's answer — its composition's `options` for that
 * block type — or else the option's own default. Applying a character's composition hands
 * every option back; it never touches content. Stored apart for that reason, in
 * page_blocks.options_json.
 *
 * TWO KINDS, the same two the design layer has: a closed set (`values`), or a stepped number
 * (`min`, `max`, `step`). Never a free value, so an option can never become a free-length or
 * free-colour field.
 *
 * @phpstan-type OptionSpec array{type: string, values: list<string>, min: int, max: int, step: int, default: string}
 */
final class BlockOptions
{
    private const KEYS = ['values', 'min', 'max', 'step', 'default'];

    /**
     * A definition's `options`, checked when the block is discovered: a broken definition
     * fails loudly at load, as every other part of block.php does.
     *
     * @return array<string, OptionSpec>
     */
    public static function validate(string $type, mixed $options): array
    {
        if (!is_array($options)) {
            BlockDefinition::fail($type, "'options' must be an array");
        }
        $specs = [];
        foreach ($options as $name => $option) {
            $at = "option '{$name}'";
            if (!is_string($name) || !preg_match(BlockDefinition::NAME, $name)) {
                BlockDefinition::fail($type, "{$at}: option names must match [a-z][a-z0-9_]*");
            }
            if (!is_array($option) || array_diff(array_keys($option), self::KEYS) !== []) {
                BlockDefinition::fail($type, "{$at}: takes only " . implode(', ', self::KEYS));
            }
            $default = $option['default'] ?? null;
            if (isset($option['values'])) {
                $values = $option['values'];
                if (!is_array($values) || $values === [] || !array_is_list($values)
                    || array_filter($values, static fn (mixed $v): bool => !is_string($v) || !preg_match(BlockDefinition::SLUG, $v)) !== []) {
                    BlockDefinition::fail($type, "{$at}: 'values' must be a non-empty list of names");
                }
                if (!in_array($default, $values, true)) {
                    BlockDefinition::fail($type, "{$at}: 'default' must be one of its values");
                }
                $specs[$name] = ['type' => 'choice', 'values' => $values, 'min' => 0, 'max' => 0, 'step' => 0, 'default' => $default];
                continue;
            }
            foreach (['min', 'max', 'step'] as $bound) {
                if (!is_int($option[$bound] ?? null)) {
                    BlockDefinition::fail($type, "{$at}: a number takes integer 'min', 'max' and 'step', or it is a closed set with 'values'");
                }
            }
            if ($option['step'] < 1 || $option['max'] <= $option['min'] || !is_int($default) || $default < $option['min'] || $default > $option['max']) {
                BlockDefinition::fail($type, "{$at}: 'default' must lie within 'min' and 'max', and 'step' be at least 1");
            }
            $specs[$name] = ['type' => 'number', 'values' => [], 'min' => $option['min'], 'max' => $option['max'], 'step' => $option['step'], 'default' => (string) $default];
        }

        return $specs;
    }

    /**
     * Stored options in their one shape: every option of the block, each '' or a value it may
     * hold. Unknown names are dropped; a value an option cannot hold becomes ''.
     *
     * @param array<string, OptionSpec> $specs
     * @return array<string, string>
     */
    public static function normalize(array $specs, mixed $stored): array
    {
        $stored = is_array($stored) ? $stored : [];
        $normalized = [];
        foreach ($specs as $name => $spec) {
            $normalized[$name] = self::clean($spec, $stored[$name] ?? '');
        }

        return $normalized;
    }

    /**
     * One option's value, or '' when it is none it may hold. A number off its step lands on
     * the nearest one.
     *
     * @param OptionSpec $spec
     */
    public static function clean(array $spec, mixed $value): string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || $value === '') {
            return '';
        }
        if ($spec['type'] === 'choice') {
            return in_array($value, $spec['values'], true) ? $value : '';
        }
        if (!is_numeric($value) || (float) $value < $spec['min'] || (float) $value > $spec['max']) {
            return '';
        }

        return (string) ($spec['min'] + (int) round(((float) $value - $spec['min']) / $spec['step']) * $spec['step']);
    }

    /**
     * What the block is drawn with: the owner's value, else the character's for this block
     * type, else the option's default. Every option answered.
     *
     * @param array<string, OptionSpec> $specs
     * @param array<string, string> $stored normalized
     * @param array<string, string> $composed the character's options for this block type
     * @return array<string, string>
     */
    public static function effective(array $specs, array $stored, array $composed): array
    {
        $effective = [];
        foreach ($specs as $name => $spec) {
            $own = $stored[$name] ?? '';
            $character = self::clean($spec, $composed[$name] ?? '');
            $effective[$name] = $own !== '' ? $own : ($character !== '' ? $character : $spec['default']);
        }

        return $effective;
    }
}
