<?php

namespace App\Modules\Design;

/**
 * Layer 2: the section style of one block instance, stored in page_blocks.style_json
 * and rendered as class names on its wrapper. The CSS for every class is
 * public/assets/sections.css.
 */
final class SectionStyle
{
    /** The closed sets of SPEC §5.4. */
    public const OPTIONS = [
        'surface' => ['plain', 'tinted', 'contrast', 'image', 'gradient'],
        'rhythm' => ['tight', 'normal', 'airy'],
        'width' => ['narrow', 'normal', 'wide', 'full'],
        'align' => ['left', 'center'],
        'divider' => ['none', 'line', 'slant', 'curve'],
    ];

    public const DEFAULTS = [
        'surface' => 'plain',
        'rhythm' => 'normal',
        'width' => 'normal',
        'align' => 'left',
        'divider' => 'none',
    ];

    /**
     * Every key present with a value from its closed set. Unknown keys are dropped and
     * unknown values fall back to the default; nothing outside the sets is ever kept.
     *
     * @return array<string, string>
     */
    public static function normalize(mixed $style): array
    {
        $normalized = [];
        foreach (self::OPTIONS as $key => $values) {
            $value = is_array($style) ? ($style[$key] ?? null) : null;
            $normalized[$key] = is_string($value) && in_array($value, $values, true) ? $value : self::DEFAULTS[$key];
        }

        return $normalized;
    }

    /**
     * @param array<string, string> $style normalized
     * @return list<string> e.g. surface-tinted, rhythm-airy
     */
    public static function classes(array $style): array
    {
        $classes = [];
        foreach (self::OPTIONS as $key => $values) {
            $classes[] = $key . '-' . ($style[$key] ?? self::DEFAULTS[$key]);
        }

        return $classes;
    }
}
