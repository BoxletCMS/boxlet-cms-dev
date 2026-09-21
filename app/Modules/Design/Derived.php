<?php

namespace App\Modules\Design;

/**
 * What the decisions PRODUCE: every custom property TokenCompiler writes (SPEC §5.4).
 *
 * Its own file because it is its own question. Tokens answers "is this a decision Boxlet
 * accepts, and what does it mean to a person"; this answers "what CSS does it come to". The
 * two grew into one file of 355 lines, which is where a file stops being read and starts
 * being searched (CLAUDE.md), and they were never the same concern.
 *
 * NOTHING HERE VALIDATES. Everything it is handed has already been through
 * Tokens::validate(), which is what lets these methods read as arithmetic rather than as a
 * second opinion about what a decision may be.
 */
final class Derived
{
    /** Type steps as powers of the scale ratio, from small print to the largest heading. */
    public const TYPE_STEPS = ['sm' => -1, 'base' => 0, 'lg' => 1, 'xl' => 2, '2xl' => 3, '3xl' => 4, '4xl' => 5];
    public const SPACE_STEPS = ['xs' => 0.25, 's' => 0.5, 'm' => 1, 'l' => 2, 'xl' => 4, '2xl' => 6, '3xl' => 8];
    public const RADII = [
        'none' => ['s' => '0', 'm' => '0', 'l' => '0', 'button' => '0'],
        'subtle' => ['s' => '0.125rem', 'm' => '0.25rem', 'l' => '0.5rem', 'button' => '0.25rem'],
        'round' => ['s' => '0.375rem', 'm' => '0.75rem', 'l' => '1.25rem', 'button' => '0.75rem'],
        'pill' => ['s' => '0.5rem', 'm' => '1rem', 'l' => '2rem', 'button' => '999rem'],
    ];

    /**
     * Every custom property the decisions produce, for TokenCompiler: group => name =>
     * value, emitted as --group-name.
     *
     * @param array<string, string> $decisions validated decisions
     * @return array<string, array<string, string>>
     */
    public static function from(array $decisions): array
    {
        $colors = Palette::colors($decisions['seed'], $decisions['secondary'], $decisions['surface_contrast'], Tokens::byHand($decisions));
        $pairing = Typography::PAIRINGS[$decisions['typography']];
        $width = Tokens::width($decisions['container']) ?? 56.0;
        $hard = $decisions['shadow'] === 'hard';

        return [
            'color' => $colors,
            'font' => ['heading' => Typography::stack($pairing['heading']), 'body' => Typography::stack($pairing['body'])],
            'heading' => ['weight' => $pairing['heading_weight'], 'tracking' => $pairing['tracking'], 'transform' => $pairing['transform']],
            'body' => ['weight' => $pairing['body_weight']],
            'leading' => ['body' => $pairing['leading_body'], 'heading' => $pairing['leading_heading']],
            'text' => self::typeScale((float) $decisions['scale'], Tokens::TEXT_SIZE[$decisions['text_size']] ?? 1.0),
            'space' => self::spaceScale(Tokens::SPACING[$decisions['spacing']]),
            'radius' => self::RADII[$decisions['radius']],
            'shadow' => self::shadows($decisions['shadow'], $colors['text']),
            // Hard shadows come with heavy rules and outlined cards; everything else is hairline.
            'border' => ['width' => $hard ? '3px' : '1px', 'card' => $hard ? '3px' : '0px'],
            'container' => ['width' => self::rem($width), 'narrow' => self::rem($width * 0.68), 'wide' => self::rem($width * 1.3)],
            'page' => self::page($decisions, $colors, Tokens::SPACING[$decisions['spacing']]),
        ];
    }

    /**
     * The page as a sheet (D-031): what sits around it, how far it is inset, and how wide
     * the header runs.
     *
     * THE FRAME IS ZERO WHEN THE PAGE IS NOT BOXED, which is what makes the background
     * decision harmless rather than conditional: there is no area around the sheet, so the
     * colour has nothing to paint and no text can land on it. One value decides it, in one
     * place, instead of every rule asking whether boxing is on.
     *
     * @param array<string, string> $decisions
     * @param array<string, string> $colors
     * @return array<string, string>
     */
    private static function page(array $decisions, array $colors, float $spacingUnit): array
    {
        $boxed = $decisions['boxed'] === 'yes';

        return [
            'bg' => $colors[$decisions['page_background']] ?? $colors['surface'],
            'frame' => $boxed ? self::rem($spacingUnit * 3) : '0',
            // The sheet keeps the page background; only what surrounds it changes.
            'sheet' => $colors['background'],
            'header-width' => $decisions['header_width'] === 'full' ? '100%' : self::rem(Tokens::width($decisions['container']) ?? 56.0),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function typeScale(float $ratio, float $base = 1.0): array
    {
        $sizes = [];
        foreach (self::TYPE_STEPS as $name => $step) {
            $size = $base * $ratio ** $step;
            if ($step < 3) {
                $sizes[$name] = self::rem($size);
                continue;
            }
            // Headings shrink on narrow screens: 72% at a 30rem viewport, full size from 75rem.
            $min = max(1.25, $size * 0.72);
            $slope = ($size - $min) / 0.45;
            $sizes[$name] = sprintf('clamp(%s, %s + %svw, %s)', self::rem($min), self::rem($min - 0.3 * $slope), self::number($slope), self::rem($size));
        }

        return $sizes;
    }

    /**
     * @return array<string, string>
     */
    private static function spaceScale(float $unit): array
    {
        return array_map(static fn (float|int $factor): string => self::rem($unit * $factor), self::SPACE_STEPS);
    }

    /**
     * @return array<string, string>
     */
    private static function shadows(string $character, string $ink): array
    {
        $rgb = Color::channels($ink);

        return match ($character) {
            'soft' => ['s' => "0 1px 3px rgb({$rgb} / 0.08)", 'm' => "0 6px 18px rgb({$rgb} / 0.1)", 'l' => "0 18px 48px rgb({$rgb} / 0.14)"],
            'hard' => ['s' => "3px 3px 0 {$ink}", 'm' => "6px 6px 0 {$ink}", 'l' => "10px 10px 0 {$ink}"],
            'layered' => [
                's' => "0 1px 1px rgb({$rgb} / 0.06), 0 2px 4px rgb({$rgb} / 0.06)",
                'm' => "0 1px 2px rgb({$rgb} / 0.06), 0 4px 8px rgb({$rgb} / 0.06), 0 12px 24px rgb({$rgb} / 0.08)",
                'l' => "0 2px 4px rgb({$rgb} / 0.05), 0 8px 16px rgb({$rgb} / 0.07), 0 24px 48px rgb({$rgb} / 0.12)",
            ],
            default => ['s' => 'none', 'm' => 'none', 'l' => 'none'],
        };
    }

    private static function rem(float $value): string
    {
        return self::number($value) . 'rem';
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
