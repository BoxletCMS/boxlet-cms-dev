<?php

namespace App\Modules\Design;

use App\Core\Blocks;
use App\Modules\Design\Vocabulary\Decisions;

/**
 * Every key a design set may hold and every value it may take, read off the code that
 * validates them (PLAN.md D-152) — and the JSON Schema written from that.
 *
 * ONE SOURCE. Vocabulary\Decisions, SectionStyle and the block registry are what DesignSet
 * checks a file against; this reads the same ones, so the
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
     * @return array{decisions: array<string, array<string, mixed>>, look: array<string, array<string, mixed>>, composition: array<string, mixed>}
     */
    public static function vocabulary(Blocks $registry): array
    {
        // Each part of the file from the one table of decisions (D-164), in its order.
        $rules = static function (string $part): array {
            $out = [];
            foreach (Decisions::ALL as $key => $definition) {
                if ($definition['part'] !== $part) {
                    continue;
                }
                $rule = ['type' => $definition['type']] + array_intersect_key($definition, array_flip(['values', 'min', 'max', 'step', 'unit']));
                if ($key === 'seed') {
                    $rule['required'] = true;
                }
                $out[$key] = $rule;
            }

            return $out;
        };
        $decisions = $rules('decisions');

        $layouts = [];
        foreach ($registry->types() as $type) {
            $layouts[$type] = array_values($registry->get($type)['layouts']);
        }
        $surfaces = array_values(array_diff(SectionStyle::OPTIONS['surface'], [SectionStyle::IMAGE]));

        return [
            'decisions' => $decisions,
            'look' => $rules('look'),
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

        // '' follows (the character, the pairing, the palette) for every key but the seed.
        $property = static fn (string $key, array $rule): array => match ($rule['type']) {
            'colour' => ['type' => 'string', 'pattern' => $key === 'seed' ? self::HEX : self::HEX_OR_EMPTY],
            'choice' => ['enum' => array_merge([''], $rule['values'])],
            'number' => ['type' => ['string', 'number'], 'description' => self::range($rule)],
            default => ['type' => 'string'],
        };
        $decisions = [];
        foreach ($vocabulary['decisions'] as $key => $rule) {
            $decisions[$key] = $property($key, $rule);
        }
        $look = [];
        foreach ($vocabulary['look'] as $key => $rule) {
            $look[$key] = $property($key, $rule);
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
                    'description' => '\'\' or a choice left out follows the character.',
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
     * A number's range in words: "1.1 – 1.6", "integer px, -30 – 40", "rem, 36 – 88, step 2".
     *
     * @param array<string, mixed> $rule
     */
    private static function range(array $rule): string
    {
        return 'number'
            . (isset($rule['unit']) ? ' ' . $rule['unit'] : '')
            . ', ' . $rule['min'] . ' – ' . $rule['max']
            . (isset($rule['step']) ? ', step ' . $rule['step'] : '');
    }
}
