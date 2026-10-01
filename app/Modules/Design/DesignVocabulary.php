<?php

namespace App\Modules\Design;

use App\Core\Blocks;
use App\Modules\Settings\ChromeLook;

/**
 * Every key a design set may hold and every value it may take, read off the code that
 * validates them (PLAN.md D-152) — and the JSON Schema written from that.
 *
 * ONE SOURCE. The constants in Tokens, Typography, ChromeLook and SectionStyle and the
 * block registry are what DesignSet checks a file against; this reads the same ones, so the
 * schema cannot list a value the validator refuses or miss one it takes. It is what
 * GET /admin/appearance/schema answers, what designs/design-set.schema.json is held equal to
 * (tests/design_set_test.php), and what a model will be told when it is asked for a design.
 *
 * Split from DesignSet: that reads and writes files, this describes them.
 */
final class DesignVocabulary
{
    /** What Color::normalizeHex() takes: #rgb or #rrggbb, either case. */
    private const HEX = '^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$';
    private const HEX_OR_EMPTY = '^(#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}))?$';

    /**
     * @return array{decisions: array<string, array<string, mixed>>, look: array<string, list<string>>, composition: array<string, mixed>}
     */
    public static function vocabulary(Blocks $registry): array
    {
        $choices = Tokens::choices();
        $decisions = [];
        // In the order design_tokens stores them, which is the order a set is written in.
        foreach (array_keys(Presets::get(Presets::DEFAULT)) as $key) {
            $decisions[$key] = match (true) {
                $key === 'seed' => ['type' => 'colour', 'required' => true],
                $key === 'secondary', str_starts_with($key, 'color_'), in_array($key, Tokens::OWN_COLOURS, true) => ['type' => 'colour', 'empty' => true],
                isset($choices[$key]) => ['type' => 'choice', 'values' => $choices[$key]],
                $key === 'heading_weight' => ['type' => 'choice', 'values' => Tokens::HEADING_WEIGHTS, 'empty' => true],
                $key === 'tracking' => ['type' => 'choice', 'values' => array_keys(Tokens::TRACKING), 'empty' => true],
                $key === 'caps' => ['type' => 'choice', 'values' => array_keys(Tokens::CAPS), 'empty' => true],
                $key === 'scale' => ['type' => 'number', 'min' => Tokens::SCALE_MIN, 'max' => Tokens::SCALE_MAX],
                isset(Tokens::NUDGES[$key]) => ['type' => 'integer', 'min' => Tokens::NUDGES[$key]['min'], 'max' => Tokens::NUDGES[$key]['max'], 'unit' => 'px'],
                $key === 'container' => ['type' => 'number', 'min' => Tokens::CONTAINER_MIN, 'max' => Tokens::CONTAINER_MAX, 'step' => Tokens::CONTAINER_STEP, 'unit' => 'rem', 'names' => array_keys(Tokens::CONTAINER_NAMES)],
                $key === 'sheet_width' => ['type' => 'number', 'min' => Tokens::SHEET_WIDTH_MIN, 'max' => Tokens::SHEET_WIDTH_MAX, 'step' => Tokens::SHEET_WIDTH_STEP, 'unit' => 'rem'],
                $key === 'sheet_gap' => ['type' => 'integer', 'min' => 0, 'max' => Tokens::SHEET_GAP_MAX, 'unit' => 'spacing units'],
                default => ['type' => 'string'],
            };
        }

        $layouts = [];
        foreach ($registry->types() as $type) {
            $layouts[$type] = array_values($registry->get($type)['layouts']);
        }
        $surfaces = array_values(array_diff(SectionStyle::OPTIONS['surface'], [SectionStyle::IMAGE]));

        return [
            'decisions' => $decisions,
            'look' => ChromeLook::OPTIONS,
            'composition' => [
                'section' => ['surface' => $surfaces] + SectionStyle::OPTIONS,
                'surfaces' => $surfaces,
                'dividers' => SectionStyle::OPTIONS['divider'],
                'layouts' => $layouts,
            ],
        ];
    }

    /**
     * The vocabulary as a JSON Schema (draft 2020-12), for editors, people and models. The
     * PHP validator stays the authority: this describes it and never decides.
     *
     * @return array<string, mixed>
     */
    public static function schema(Blocks $registry): array
    {
        $vocabulary = self::vocabulary($registry);

        $decisions = [];
        foreach ($vocabulary['decisions'] as $key => $rule) {
            $decisions[$key] = match ($rule['type']) {
                'colour' => ['type' => 'string', 'pattern' => !empty($rule['empty']) ? self::HEX_OR_EMPTY : self::HEX],
                'choice' => ['enum' => array_merge(!empty($rule['empty']) ? [''] : [], $rule['values'])],
                'number', 'integer' => ['type' => ['string', 'number'], 'description' => self::range($rule)],
                default => ['type' => 'string'],
            };
        }
        $look = [];
        foreach ($vocabulary['look'] as $choice => $values) {
            $look[$choice] = ['enum' => array_merge([''], $values)];
        }
        $section = [];
        foreach ($vocabulary['composition']['section'] as $key => $values) {
            $section[$key] = ['enum' => $values];
        }
        $layouts = [];
        foreach ($vocabulary['composition']['layouts'] as $type => $values) {
            $layouts[$type] = ['enum' => $values];
        }
        $localized = static fn (int $length): array => [
            'type' => 'object',
            'minProperties' => 1,
            'propertyNames' => ['pattern' => '^[a-z]{2}(-[A-Z]{2})?$'],
            'additionalProperties' => ['type' => 'string', 'maxLength' => $length],
        ];

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'boxlet-design-set-v' . DesignSet::VERSION,
            'title' => 'Boxlet design set (v' . DesignSet::VERSION . ')',
            'description' => 'A portable Boxlet design: decisions, header and footer look, and optionally a composition, which makes it a character. Generated from the code that validates it; Boxlet\'s own validator decides. Keys left out of decisions take the neutral defaults. A key Boxlet does not know is left out with a warning.',
            'type' => 'object',
            'required' => ['format', 'version', 'id', 'name', 'decisions'],
            'additionalProperties' => false,
            'properties' => [
                '$schema' => ['type' => 'string'],
                'format' => ['const' => DesignSet::FORMAT],
                'version' => ['const' => DesignSet::VERSION],
                'id' => ['type' => 'string', 'pattern' => trim(DesignSet::ID_PATTERN, '~')],
                'name' => $localized(80),
                'description' => $localized(300),
                'author' => ['type' => 'string', 'maxLength' => 80],
                'tags' => ['type' => 'array', 'maxItems' => 12, 'items' => ['type' => 'string', 'maxLength' => 32]],
                'decisions' => [
                    'type' => 'object',
                    'required' => ['seed'],
                    'additionalProperties' => false,
                    'properties' => $decisions,
                ],
                'look' => [
                    'type' => 'object',
                    'description' => '\'\' follows the character. A character sets every choice.',
                    'additionalProperties' => false,
                    'properties' => $look,
                ],
                'composition' => [
                    'type' => 'object',
                    'description' => 'Present: the set can be a character. Block types this site does not have, and layouts a block does not offer, are left out with a warning.',
                    'required' => ['section'],
                    'additionalProperties' => false,
                    'properties' => [
                        'section' => ['type' => 'object', 'additionalProperties' => false, 'properties' => $section],
                        'surfaces' => ['type' => 'object', 'additionalProperties' => ['enum' => $vocabulary['composition']['surfaces']]],
                        'dividers' => ['type' => 'object', 'additionalProperties' => ['enum' => $vocabulary['composition']['dividers']]],
                        'layouts' => ['type' => 'object', 'properties' => $layouts, 'additionalProperties' => ['type' => 'string']],
                    ],
                ],
            ],
        ];
    }

    /** The schema as the file in designs/ holds it, byte for byte. */
    public static function schemaJson(Blocks $registry): string
    {
        return json_encode(self::schema($registry), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * A number's range in words: "1.1 – 1.6", "integer px, -30 – 40", "rem, 36 – 88, step 2;
     * also narrow, normal, wide, full".
     *
     * @param array<string, mixed> $rule
     */
    private static function range(array $rule): string
    {
        $text = ($rule['type'] === 'integer' ? 'integer' : 'number')
            . (isset($rule['unit']) ? ' ' . $rule['unit'] : '')
            . ', ' . $rule['min'] . ' – ' . $rule['max']
            . (isset($rule['step']) ? ', step ' . $rule['step'] : '');

        return isset($rule['names']) ? $text . '; also ' . implode(', ', $rule['names']) : $text;
    }
}
