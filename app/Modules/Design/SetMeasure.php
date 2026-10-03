<?php

namespace App\Modules\Design;

use App\Core\Blocks;
use App\Modules\Pages\SectionLayout;

/**
 * HOW WIDE A SET LETS WORDS RUN, WORKED OUT WITHOUT A BROWSER (PLAN.md D-182, D-183): the
 * narrowest column of words each block's layout gives under a set's decisions and composition,
 * on a large screen, for bin/check-set.php to warn about one under 16rem.
 *
 * The arithmetic of the stylesheets, written out: a section's width from the content width
 * (narrow two thirds of it, wide seven sixths, as Derived draws them, and never wider than a
 * boxed sheet), a section's columns with their gap (sections.css), and the share of a block's
 * width its words get in each layout (blocks*.css). Below 64rem of window the columns stack, so
 * a large screen is where words are narrowest beside something; narrower screens are what
 * scenario 64 measures in a browser.
 *
 * Not worked out: Call to action beside, whose buttons take the width their words need, and a
 * repeater's items, which have grids of their own (as in scenario 64).
 *
 * Measured against the browser under Editorial (D-183), to the tenth of a rem: a split hero
 * 24.0, Form beside 14.8, a cover hero 28.0, the narrow column of wide-left 13.2.
 */
final class SetMeasure
{
    /** The narrowest a column of words may be, in rem (D-182). */
    public const LEAST = 16.0;

    /**
     * Layout => [share of the block's width the words get, the gap taken out first, in units
     * of the spacing decision], from the grid templates in blocks*.css. A layout not listed
     * gives its words the whole width.
     */
    private const SHARES = [
        // .layout-split .hero: 1.1fr 1fr, gap --space-columns (2.5 × spacing).
        'hero/split' => [1.1 / 2.1, 2.5],
        // .image-text: 1fr 1fr, gap --space-columns.
        'image_text/image-left' => [0.5, 2.5],
        'image_text/image-right' => [0.5, 2.5],
        // .layout-columns .richtext: columns 2, gap --space-xl (4 × spacing).
        'text/columns' => [0.5, 4.0],
        // .layout-beside .form-block: 2fr 3fr, gap --space-xl; the words are the 2.
        'form/beside' => [0.4, 4.0],
    ];

    /** A cover hero's words keep to the narrow measure (blocks-hero.css). */
    private const NARROW_MEASURE = ['hero/cover-center', 'hero/cover-left', 'hero/cover-right', 'hero/cover-low'];

    /**
     * Every place a set's own choices put words in a column under 16rem, in words.
     *
     * @param array<string, string> $decisions resolved
     * @param array<string, mixed>|null $composition as DesignSet reads it
     * @param list<array<string, mixed>> $patterns as DesignSet reads them
     * @return list<string>
     */
    public static function narrow(array $decisions, ?array $composition, array $patterns, Blocks $registry): array
    {
        $found = [];
        $width = (string) ($composition['section']['width'] ?? 'normal');
        foreach ($registry->types() as $type) {
            $layout = (string) ($composition['layouts'][$type] ?? $registry->layout($type, null));
            // A split hero with its picture is drawn at the wide measure (SectionRender).
            $at = $type === 'hero' && $layout === 'split' && in_array($width, ['narrow', 'normal'], true) ? 'wide' : $width;
            $words = self::words($decisions, $at, SectionLayout::ONE, 0, $type, $layout);
            if ($words !== null && $words < self::LEAST) {
                $found[] = sprintf('%s, %s, in a %s section: words %.1frem wide', $type, $layout, $width, $words);
            }
        }
        // Any other layout an owner may choose, in the same section.
        foreach ($registry->types() as $type) {
            $composed = (string) ($composition['layouts'][$type] ?? $registry->layout($type, null));
            foreach ($registry->get($type)['layouts'] as $layout) {
                if ($layout === $composed) {
                    continue;
                }
                $at = $type === 'hero' && $layout === 'split' && in_array($width, ['narrow', 'normal'], true) ? 'wide' : $width;
                $words = self::words($decisions, $at, SectionLayout::ONE, 0, $type, $layout);
                if ($words !== null && $words < self::LEAST) {
                    $found[] = sprintf('%s, %s if an owner chooses it, in a %s section: words %.1frem wide', $type, $layout, $width, $words);
                }
            }
        }
        foreach ($patterns as $pattern) {
            $style = $pattern['section']['style'] ?? [];
            $sectionWidth = is_string($style['width'] ?? null) && $style['width'] !== '' ? $style['width'] : $width;
            foreach ($pattern['blocks'] as $block) {
                // A pattern has no pictures: its split hero is words alone, as wide as Left's.
                $layout = $block['type'] === 'hero' && $block['layout'] === 'split' ? 'left' : (string) $block['layout'];
                $words = self::words($decisions, $sectionWidth, (string) $pattern['section']['layout'], (int) $block['column'], (string) $block['type'], $layout);
                if ($words !== null && $words < self::LEAST) {
                    $found[] = sprintf('pattern %s: %s, %s, in column %d of %s: words %.1frem wide', $pattern['id'], $block['type'], $block['layout'], $block['column'] + 1, $pattern['section']['layout'], $words);
                }
            }
        }

        return $found;
    }

    /**
     * The width of a block's words in rem, on a large screen; null where it is not worked out.
     *
     * @param array<string, string> $decisions resolved
     */
    public static function words(array $decisions, string $width, string $sectionLayout, int $column, string $type, string $layout): ?float
    {
        if ($type === 'cta' && $layout === 'beside') {
            return null;
        }
        $unit = (float) $decisions['spacing'];
        $container = (float) $decisions['container'];
        $section = match ($width) {
            'narrow' => $container * 2 / 3,
            'wide', 'full' => $container * 7 / 6,
            default => $container,
        };
        if (($decisions['boxed'] ?? 'no') === 'yes') {
            // The sheet keeps the section's gutter, --space-l, on each side.
            $section = min($section, (float) $decisions['sheet_width'] - 2 * 2 * $unit);
        }
        // The section's columns, --space-l apart (sections.css).
        $weights = SectionLayout::LAYOUTS[$sectionLayout] ?? [1];
        $column = max(0, min($column, count($weights) - 1));
        $available = ($section - (count($weights) - 1) * 2 * $unit) * $weights[$column] / array_sum($weights);

        $key = $type . '/' . $layout;
        if (in_array($key, self::NARROW_MEASURE, true)) {
            return min($available, $container * 2 / 3);
        }
        if (isset(self::SHARES[$key])) {
            [$share, $gap] = self::SHARES[$key];

            return ($available - $gap * $unit) * $share;
        }

        return $available;
    }
}
