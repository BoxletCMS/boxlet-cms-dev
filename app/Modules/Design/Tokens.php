<?php

namespace App\Modules\Design;

/**
 * Layer 1: eight decisions, not forty values (SPEC §5.4). Validates them and derives
 * every custom property the site uses from them.
 */
final class Tokens
{
    public const SCALES = ['1.125', '1.2', '1.25', '1.333', '1.414', '1.5'];
    /** Spacing base unit in rem; the whole spacing scale is multiples of it. */
    public const SPACING = ['compact' => 0.875, 'normal' => 1.0, 'roomy' => 1.25, 'generous' => 1.5];
    public const RADIUS = ['none', 'subtle', 'round', 'pill'];
    public const SHADOW = ['none', 'soft', 'hard', 'layered'];
    /** Container width in rem. */
    public const CONTAINER = ['narrow' => 42.0, 'normal' => 56.0, 'wide' => 68.0, 'full' => 80.0];
    public const SURFACE_CONTRAST = ['low', 'medium', 'high'];

    /*
     * The page itself (PLAN.md D-031).
     *
     * Two values for the header, not a second four-value container decision: the header
     * either holds the same measure as the content or runs edge to edge.
     *
     * BOXED IS 'no'/'yes' RATHER THAN A BOOLEAN, so it is one of the closed-set decisions
     * like every other: design_tokens stores JSON scalars, choices() lists allowed strings
     * and the screen renders a select from them. A bool would be the single exception in
     * all three.
     *
     * The page background is a SHADE FROM THE PALETTE, never a free colour — the reasoning
     * §5.4 uses to refuse a free colour per section. It shows only around a boxed page, so
     * no text ever sits on it and Palette::failures() gains no pairs.
     */
    public const HEADER_WIDTH = ['content', 'full'];
    public const BOXED = ['no', 'yes'];
    public const PAGE_BACKGROUND = ['surface', 'border', 'contrast'];

    /** Type steps as powers of the scale ratio, from small print to the largest heading. */
    private const TYPE_STEPS = ['sm' => -1, 'base' => 0, 'lg' => 1, 'xl' => 2, '2xl' => 3, '3xl' => 4, '4xl' => 5];
    private const SPACE_STEPS = ['xs' => 0.25, 's' => 0.5, 'm' => 1, 'l' => 2, 'xl' => 4, '2xl' => 6, '3xl' => 8];
    private const RADII = [
        'none' => ['s' => '0', 'm' => '0', 'l' => '0', 'button' => '0'],
        'subtle' => ['s' => '0.125rem', 'm' => '0.25rem', 'l' => '0.5rem', 'button' => '0.25rem'],
        'round' => ['s' => '0.375rem', 'm' => '0.75rem', 'l' => '1.25rem', 'button' => '0.75rem'],
        'pill' => ['s' => '0.5rem', 'm' => '1rem', 'l' => '2rem', 'button' => '999rem'],
    ];

    /**
     * The closed decisions and their allowed values. The two colours are open (#rrggbb).
     *
     * @return array<string, list<string>>
     */
    public static function choices(): array
    {
        return [
            'typography' => array_keys(Typography::PAIRINGS),
            'scale' => self::SCALES,
            'spacing' => array_keys(self::SPACING),
            'radius' => self::RADIUS,
            'shadow' => self::SHADOW,
            'container' => array_keys(self::CONTAINER),
            'surface_contrast' => self::SURFACE_CONTRAST,
            'header_width' => self::HEADER_WIDTH,
            'boxed' => self::BOXED,
            'page_background' => self::PAGE_BACKGROUND,
        ];
    }

    /**
     * Checks submitted decisions: colours as #rrggbb, everything else one of its choices,
     * and every text/background pair of the resulting palette at WCAG AA. Errors are keyed
     * by the decision that caused them; invalid values are replaced by the default preset's
     * so the returned decisions can always be previewed.
     *
     * @param array<mixed> $input
     * @return array{decisions: array<string, string>, errors: array<string, string>}
     */
    public static function validate(array $input): array
    {
        $fallback = Presets::get(Presets::DEFAULT);
        $errors = [];

        $seed = Color::normalizeHex(is_string($input['seed'] ?? null) ? $input['seed'] : '');
        if ($seed === null) {
            $errors['seed'] = t('design.error.color');
        }
        $secondaryInput = is_string($input['secondary'] ?? null) ? trim($input['secondary']) : '';
        $secondary = $secondaryInput === '' ? '' : Color::normalizeHex($secondaryInput);
        if ($secondary === null) {
            $errors['secondary'] = t('design.error.color');
        }
        $decisions = ['seed' => $seed ?? $fallback['seed'], 'secondary' => $secondary ?? ''];

        foreach (self::choices() as $key => $allowed) {
            $value = $input[$key] ?? null;
            if (!is_string($value) || !in_array($value, $allowed, true)) {
                $errors[$key] = t('design.error.choice');
                $value = $fallback[$key];
            }
            $decisions[$key] = $value;
        }

        $colors = Palette::colors($decisions['seed'], $decisions['secondary'], $decisions['surface_contrast']);
        foreach (Palette::failures($colors, $decisions['secondary'] !== '') as $failure) {
            $message = t('design.error.contrast', [
                'pair' => t('design.pair.' . $failure['pair']),
                'ratio' => number_format($failure['ratio'], 2),
                'required' => number_format($failure['required'], 1),
            ]);
            $key = $failure['decision'];
            $errors[$key] = isset($errors[$key]) ? $errors[$key] . ' ' . $message : $message;
        }

        return ['decisions' => $decisions, 'errors' => $errors];
    }

    /**
     * Every custom property the decisions produce, for TokenCompiler: group => name =>
     * value, emitted as --group-name.
     *
     * @param array<string, string> $decisions validated decisions
     * @return array<string, array<string, string>>
     */
    public static function derive(array $decisions): array
    {
        $colors = Palette::colors($decisions['seed'], $decisions['secondary'], $decisions['surface_contrast']);
        $pairing = Typography::PAIRINGS[$decisions['typography']];
        $width = self::CONTAINER[$decisions['container']];
        $hard = $decisions['shadow'] === 'hard';

        return [
            'color' => $colors,
            'font' => ['heading' => Typography::stack($pairing['heading']), 'body' => Typography::stack($pairing['body'])],
            'heading' => ['weight' => $pairing['heading_weight'], 'tracking' => $pairing['tracking'], 'transform' => $pairing['transform']],
            'body' => ['weight' => $pairing['body_weight']],
            'leading' => ['body' => $pairing['leading_body'], 'heading' => $pairing['leading_heading']],
            'text' => self::typeScale((float) $decisions['scale']),
            'space' => self::spaceScale(self::SPACING[$decisions['spacing']]),
            'radius' => self::RADII[$decisions['radius']],
            'shadow' => self::shadows($decisions['shadow'], $colors['text']),
            // Hard shadows come with heavy rules and outlined cards; everything else is hairline.
            'border' => ['width' => $hard ? '3px' : '1px', 'card' => $hard ? '3px' : '0px'],
            'container' => ['width' => self::rem($width), 'narrow' => self::rem($width * 0.68), 'wide' => self::rem($width * 1.3)],
            'page' => self::page($decisions, $colors, self::SPACING[$decisions['spacing']]),
        ];
    }

    /**
     * The same decisions in numbers a person can read, for the lines under the controls
     * (PLAN.md D-058).
     *
     * WHAT WAS THERE BEFORE WAS CSS. The screen printed
     * `clamp(2.038rem, 1.508rem + 1.759vw, 2.827rem)` and a row of rem values, which is the
     * compiler's own language leaking onto the owner's screen: it told a person who has
     * never written a stylesheet nothing, and it told one who has nothing they could not
     * read off the page itself.
     *
     * PIXELS AT THE BROWSER'S DEFAULT of 16px to the rem. That is an assumption, and it is
     * the right one to make here: a reader who has changed it is reading a page whose every
     * size moves with their setting, and the number is a sense of scale, not a promise.
     *
     * The specimen is the preview beside these lines — the admin cannot show one, because
     * its own type is fixed by the --ui-* set and must never follow the site's (SPEC §5.4).
     *
     * @param array<string, string> $decisions validated decisions
     * @return array{text: array<string, int>, text_phone: int, space: int, section: int, radius: int, container: int, container_rem: float}
     */
    public static function readable(array $decisions): array
    {
        $px = static fn (float $rem): int => (int) round($rem * 16);
        $ratio = (float) $decisions['scale'];
        $sizes = [];
        foreach (self::TYPE_STEPS as $name => $step) {
            $sizes[$name] = $px($ratio ** $step);
        }
        $unit = self::SPACING[$decisions['spacing']];
        $width = self::CONTAINER[$decisions['container']];

        return [
            'text' => $sizes,
            // What the largest heading shrinks to on a narrow screen, by the same rule
            // typeScale() uses to build the clamp.
            'text_phone' => $px(max(1.25, $ratio ** 5 * 0.72)),
            'space' => $px($unit),
            'section' => $px($unit * self::SPACE_STEPS['2xl']),
            'radius' => $px((float) rtrim(self::RADII[$decisions['radius']]['m'], 'rem')),
            'container' => $px($width),
            'container_rem' => $width,
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
            'header-width' => $decisions['header_width'] === 'full' ? '100%' : self::rem(self::CONTAINER[$decisions['container']]),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function typeScale(float $ratio): array
    {
        $sizes = [];
        foreach (self::TYPE_STEPS as $name => $step) {
            $size = $ratio ** $step;
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
