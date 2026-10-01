<?php

namespace App\Modules\Design;

use App\Core\Blocks;
use App\Modules\Settings\ChromeLook;

/**
 * The three bodies of a design set — decisions, look, composition — each checked by the
 * code that owns its values (PLAN.md D-152).
 *
 * Split from DesignSet, which reads the envelope (format, version, id, names) and writes
 * files. The seam is the one the format itself has: the envelope is this file format's own,
 * while each body is a thing Boxlet already validates elsewhere, and this only hands it to
 * that code — Tokens, ChromeLook, SectionStyle and the block registry — and reports what it
 * said, field by field.
 *
 * Errors and warnings are appended to the caller's lists, each naming its field as the
 * file spells it (`decisions.seed`, `look.nav_style`, `composition.layouts.hero`).
 */
final class DesignSetParts
{
    /**
     * Every decision, validated, in the order design_tokens stores them. Keys a set leaves
     * out take the neutral defaults; a key the format does not have is refused, because a
     * decision this Boxlet does not know could be one whose meaning it would get wrong.
     *
     * @param list<string> $errors
     * @return array<string, string>
     */
    public static function decisions(mixed $raw, array &$errors): array
    {
        if (!is_array($raw)) {
            $errors[] = self::field('decisions', t('designset.missing'));

            return [];
        }
        $known = array_keys(Presets::get(Presets::DEFAULT));
        $given = [];
        foreach ($raw as $key => $value) {
            $key = (string) $key;
            if (!in_array($key, $known, true)) {
                $errors[] = self::field('decisions.' . $key, t('designset.unknown_decision'));
                continue;
            }
            if (is_string($value)) {
                $given[$key] = $value;
            } elseif ((is_int($value) || is_float($value)) && DesignSet::numeric($key)) {
                $given[$key] = (string) $value;
            } else {
                $errors[] = self::field('decisions.' . $key, t('design.error.choice'));
            }
        }
        if (!isset($given['seed'])) {
            $errors[] = self::field('decisions.seed', t('designset.missing'));

            return [];
        }

        // Contrast failures arrive here like any other error, and refuse the set (D-154).
        $result = Tokens::validate($given + Presets::neutral());
        foreach ($result['errors'] as $key => $message) {
            $errors[] = self::field('decisions.' . $key, $message);
        }

        return $result['decisions'];
    }

    /**
     * Every header and footer choice. '' follows the character, which a design may do and
     * a character may not: a character IS what the others follow, so it says all of them.
     *
     * @param list<string> $errors
     * @return array<string, string>
     */
    public static function look(mixed $raw, bool $character, array &$errors): array
    {
        $raw = $raw ?? [];
        if (!is_array($raw)) {
            $errors[] = self::field('look', t('designset.missing'));

            return ChromeLook::clean([]);
        }
        foreach ($raw as $key => $value) {
            $key = (string) $key;
            $options = ChromeLook::OPTIONS[$key] ?? null;
            if ($options === null) {
                $errors[] = self::field('look.' . $key, t('designset.unknown_choice'));
                continue;
            }
            if (!is_string($value) || ($value !== '' && !in_array($value, $options, true))) {
                $errors[] = self::field('look.' . $key, t('design.error.choice'));
            }
        }

        $look = ChromeLook::clean($raw);
        if ($character) {
            foreach ($look as $key => $value) {
                if ($value === '') {
                    $errors[] = self::field('look.' . $key, t('designset.look_incomplete'));
                }
            }
        }

        return $look;
    }

    /**
     * Layers 2 and 3: the section style every block starts from, and what a block type
     * does differently. A block type this site does not have is left out with a warning,
     * and so is a layout a block does not offer — the set still means the same everywhere
     * else. A surface is never `image`: that would be a picture, and pictures are content.
     *
     * @param list<string> $errors
     * @param list<string> $warnings
     * @return array{section: array<string, string>, surfaces: array<string, string>, dividers: array<string, string>, layouts: array<string, string>}|null
     */
    public static function composition(mixed $raw, Blocks $registry, array &$errors, array &$warnings): ?array
    {
        if (!is_array($raw) || !is_array($raw['section'] ?? null)) {
            $errors[] = self::field('composition.section', t('designset.missing'));

            return null;
        }
        foreach (array_keys($raw) as $key) {
            if (!in_array($key, ['section', 'surfaces', 'dividers', 'layouts'], true)) {
                $warnings[] = t('designset.unknown_key', ['key' => 'composition.' . $key]);
            }
        }

        $surfaces = array_values(array_diff(SectionStyle::OPTIONS['surface'], [SectionStyle::IMAGE]));
        $section = [];
        foreach ($raw['section'] as $key => $value) {
            $key = (string) $key;
            $allowed = $key === 'surface' ? $surfaces : (SectionStyle::OPTIONS[$key] ?? null);
            if ($allowed === null) {
                $warnings[] = t('designset.unknown_key', ['key' => 'composition.section.' . $key]);
            } elseif (!is_string($value) || !in_array($value, $allowed, true)) {
                $errors[] = self::field('composition.section.' . $key, t('design.error.choice'));
            } else {
                $section[$key] = $value;
            }
        }

        // The five keys in their own order; a key left out is SectionStyle's default. Never the
        // picture: normalize() adds its slot, and a composition has no picture to put in it.
        $normalized = SectionStyle::normalize($section);
        $style = [];
        foreach (array_keys(SectionStyle::OPTIONS) as $key) {
            $style[$key] = (string) $normalized[$key];
        }
        $out = [
            'section' => $style,
            'surfaces' => self::byType($raw['surfaces'] ?? [], 'surfaces', $surfaces, $registry, $errors, $warnings),
            'dividers' => self::byType($raw['dividers'] ?? [], 'dividers', SectionStyle::OPTIONS['divider'], $registry, $errors, $warnings),
            'layouts' => [],
        ];
        foreach (is_array($raw['layouts'] ?? null) ? $raw['layouts'] : [] as $type => $layout) {
            $type = (string) $type;
            if (!$registry->has($type)) {
                $warnings[] = t('designset.block_unknown', ['field' => 'composition.layouts.' . $type]);
            } elseif (!is_string($layout) || !in_array($layout, $registry->get($type)['layouts'], true)) {
                $warnings[] = t('designset.layout_unknown', ['field' => 'composition.layouts.' . $type]);
            } else {
                $out['layouts'][$type] = $layout;
            }
        }

        return $out;
    }

    /**
     * A block type => value map, each value from its closed set.
     *
     * @param list<string> $allowed
     * @param list<string> $errors
     * @param list<string> $warnings
     * @return array<string, string>
     */
    private static function byType(mixed $raw, string $name, array $allowed, Blocks $registry, array &$errors, array &$warnings): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $type => $value) {
            $type = (string) $type;
            if (!$registry->has($type)) {
                $warnings[] = t('designset.block_unknown', ['field' => 'composition.' . $name . '.' . $type]);
            } elseif (!is_string($value) || !in_array($value, $allowed, true)) {
                $errors[] = self::field('composition.' . $name . '.' . $type, t('design.error.choice'));
            } else {
                $out[$type] = $value;
            }
        }

        return $out;
    }

    private static function field(string $field, string $reason): string
    {
        return t('designset.field', ['field' => $field, 'reason' => $reason]);
    }
}
