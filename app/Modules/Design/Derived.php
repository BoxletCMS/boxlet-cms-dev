<?php

namespace App\Modules\Design;

/**
 * What the decisions PRODUCE: every custom property TokenCompiler writes (SPEC §5.4;
 * PLAN.md D-164).
 *
 * NOTHING HERE VALIDATES. It is handed a design with every key answered (Tokens::resolve()),
 * which is what lets these methods read as arithmetic. Every number reaches CSS through
 * CssNumber, so the same design compiles to the same bytes on every PHP (O-40).
 *
 * The page's own frame and the chrome's numbers are DerivedPage's; this file is the type,
 * the space, the shapes and the buttons.
 */
final class Derived
{
    /** Type steps as powers of the scale ratio, from small print to the largest heading. */
    public const TYPE_STEPS = ['sm' => -1, 'base' => 0, 'lg' => 1, 'xl' => 2, '2xl' => 3, '3xl' => 4, '4xl' => 5];
    public const SPACE_STEPS = ['xs' => 0.25, 's' => 0.5, 'm' => 1, 'l' => 2, 'xl' => 4, '2xl' => 6, '3xl' => 8];

    /** Small and large corners as fixed shares of the decision's own (README 1.2). */
    private const RADIUS_SHARES = ['s' => 0.5, 'm' => 1.0, 'l' => 1.67];

    /** What a heading shrinks to on a phone: a share of its size, never below 1.25rem. */
    private const PHONE_SHARE = 0.63;

    /**
     * Every custom property the decisions produce, for TokenCompiler: group => name =>
     * value, emitted as --group-name.
     *
     * @param array<string, string> $resolved every key answered
     * @return array<string, array<string, string>>
     */
    public static function from(array $resolved): array
    {
        $colors = Palette::forDecisions($resolved);
        $pairing = Typography::PAIRINGS[$resolved['typography']] ?? Typography::PAIRINGS['modern'];
        $unit = (float) $resolved['spacing'];

        return [
            'color' => $colors,
            'font' => ['heading' => Typography::stack($pairing['heading']), 'body' => Typography::stack($pairing['body'])],
            // The pairing's own treatment, unless the owner has taken one over (D-066).
            'heading' => [
                'weight' => $resolved['heading_weight'] !== '' ? $resolved['heading_weight'] : $pairing['heading_weight'],
                'tracking' => $resolved['tracking'] !== '' ? CssNumber::of((float) $resolved['tracking']) . 'em' : $pairing['tracking'],
                'transform' => Tokens::CAPS[$resolved['caps']] ?? $pairing['transform'],
            ],
            'body' => ['weight' => $pairing['body_weight']],
            'leading' => [
                'body' => $resolved['line_height'] !== '' ? $resolved['line_height'] : $pairing['leading_body'],
                'heading' => $pairing['leading_heading'],
            ],
            'text' => self::typeScale($resolved),
            'space' => self::spaceScale($unit) + [
                // The default padding of a section, top and bottom (README 1.6: 80px).
                'section' => CssNumber::rem((float) $resolved['section_gap']),
                // Between the columns of a section, and between blocks in one column.
                'columns' => self::rem($unit * 2.5),
                'blocks' => self::rem($unit * 1.5),
            ],
            'radius' => self::radii((float) $resolved['radius'], (float) $resolved['button_radius']),
            'shadow' => self::shadows($resolved['shadow'], (float) $resolved['shadow_strength'], $colors['text']),
            // The width of every rule and outline; a card is outlined only with hard shadows.
            'border' => [
                'width' => CssNumber::of((float) $resolved['border_width']) . 'px',
                'card' => $resolved['shadow'] === 'hard' ? CssNumber::of(max(2.0, (float) $resolved['border_width'])) . 'px' : '0px',
            ],
            'button' => [
                'height' => CssNumber::rem((float) $resolved['button_height']),
                'transform' => $resolved['button_caps'] === 'yes' ? 'uppercase' : 'none',
            ],
            'container' => [
                'width' => self::rem((float) $resolved['container']),
                // README 1.6 at 60rem: narrow 640px, wide 1120px.
                'narrow' => self::rem((float) $resolved['container'] * 2 / 3),
                'wide' => self::rem((float) $resolved['container'] * 7 / 6),
            ],
            'page' => DerivedPage::page($resolved, $colors),
            'chrome' => DerivedPage::chrome($resolved, $colors),
        ];
    }

    /**
     * ONE PLACE WHERE A SIZE IS WORKED OUT (D-066), in rem: text size × scale^step, plus the
     * step's nudge in pixels, never below half a rem.
     *
     * @param array<string, string> $resolved
     */
    public static function sizeOf(array $resolved, string $step): float
    {
        $ratio = (float) $resolved['scale'];
        $base = (float) $resolved['text_size'] / 16;
        $nudge = 0.0;
        foreach (Tokens::NUDGES as $key => $bounds) {
            if ($bounds['step'] === $step) {
                $nudge = (float) ($resolved[$key] ?? 0) / 16;
            }
        }

        return max(0.5, $base * $ratio ** self::TYPE_STEPS[$step] + $nudge);
    }

    /** What a heading of $size rem comes to on a phone. */
    public static function phoneSize(float $size): float
    {
        return max(1.25, $size * self::PHONE_SHARE);
    }

    /**
     * @param array<string, string> $resolved
     * @return array<string, string>
     */
    private static function typeScale(array $resolved): array
    {
        $sizes = [];
        foreach (self::TYPE_STEPS as $name => $step) {
            $size = self::sizeOf($resolved, $name);
            if ($step < 3) {
                $sizes[$name] = self::rem($size);
                continue;
            }
            // Headings shrink on narrow screens: the phone size at a 22rem viewport, full
            // size from 75rem.
            $min = self::phoneSize($size);
            $slope = ($size - $min) / 0.53;
            $sizes[$name] = sprintf('clamp(%s, %s + %svw, %s)', self::rem($min), self::rem($min - 0.22 * $slope), CssNumber::of($slope), self::rem($size));
        }
        // The lead paragraph under a heading (README 1.6: 18px at a 16px body).
        $sizes['lead'] = self::rem(self::sizeOf($resolved, 'base') * 1.125);

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
     * The corners: s, m and l as shares of the decision, and the buttons' own — 28 is a pill.
     *
     * @return array<string, string>
     */
    private static function radii(float $radius, float $button): array
    {
        $radii = [];
        foreach (self::RADIUS_SHARES as $name => $share) {
            $radii[$name] = CssNumber::rem($radius * $share);
        }
        $radii['button'] = $button >= 28 ? '999px' : CssNumber::rem($button);

        return $radii;
    }

    /**
     * The three shadow sizes for a style, at a strength of 0–100 (40 is what soft always was).
     *
     * @return array<string, string>
     */
    public static function shadows(string $style, float $strength, string $ink): array
    {
        $rgb = Color::channels($ink);
        $alpha = static fn (float $at40): string => CssNumber::of($at40 * $strength / 40, 3);
        $offset = static fn (float $at50): string => CssNumber::of(max(0.0, $at50 * $strength / 50)) . 'px';

        return match ($style) {
            'soft' => [
                's' => "0 1px 3px rgb({$rgb} / {$alpha(0.08)})",
                'm' => "0 6px 18px rgb({$rgb} / {$alpha(0.1)})",
                'l' => "0 18px 48px rgb({$rgb} / {$alpha(0.14)})",
            ],
            'hard' => ['s' => "{$offset(3)} {$offset(3)} 0 {$ink}", 'm' => "{$offset(6)} {$offset(6)} 0 {$ink}", 'l' => "{$offset(10)} {$offset(10)} 0 {$ink}"],
            'layered' => [
                's' => "0 1px 1px rgb({$rgb} / {$alpha(0.06)}), 0 2px 4px rgb({$rgb} / {$alpha(0.06)})",
                'm' => "0 1px 2px rgb({$rgb} / {$alpha(0.06)}), 0 4px 8px rgb({$rgb} / {$alpha(0.06)}), 0 12px 24px rgb({$rgb} / {$alpha(0.08)})",
                'l' => "0 2px 4px rgb({$rgb} / {$alpha(0.05)}), 0 8px 16px rgb({$rgb} / {$alpha(0.07)}), 0 24px 48px rgb({$rgb} / {$alpha(0.12)})",
            ],
            default => ['s' => 'none', 'm' => 'none', 'l' => 'none'],
        };
    }

    public static function rem(float $value): string
    {
        return CssNumber::of($value) . 'rem';
    }
}
