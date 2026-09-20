<?php

namespace App\Modules\Design;

/**
 * Derives the whole palette from one or two seed colours, then checks every
 * text/background pair it produces against WCAG AA.
 *
 * Seeds are used as given and never nudged to pass: the seed is the accent, the button
 * colour and the link colour, so a seed too light to read as text fails, naming the
 * pair, instead of being silently turned into a different colour.
 */
final class Palette
{
    /** How far the tinted surface sits from the page background, in OKLCH lightness. */
    public const SURFACE_STEPS = ['low' => 0.03, 'medium' => 0.065, 'high' => 0.11];

    /** WCAG AA for normal-size text. Every pair below can hold body text, so all use it. */
    public const AA_BODY = 4.5;

    /**
     * @return array<string, string> colour name => #rrggbb, emitted as --color-{name}
     */
    public static function colors(string $seed, string $secondary, string $surfaceContrast): array
    {
        [$seedLightness, $seedChroma, $hue] = Color::toOklch($seed);
        $step = self::SURFACE_STEPS[$surfaceContrast] ?? self::SURFACE_STEPS['low'];
        // Neutrals carry a trace of the seed's hue, so greys belong to the palette.
        $tint = min($seedChroma, 0.14) * 0.1;
        $ink = min($seedChroma, 0.08) * 0.35;

        $colors = [
            'background' => Color::fromOklch(0.99, $tint * 0.4, $hue),
            'surface' => Color::fromOklch(0.99 - $step, $tint, $hue),
            'border' => Color::fromOklch(0.99 - $step - 0.1, $tint * 1.5, $hue),
            'text' => Color::fromOklch(0.2, $ink, $hue),
            'muted' => Color::fromOklch(0.45, $ink, $hue),
            'accent' => $seed,
            'link' => $seed,
        ];
        $colors['on-accent'] = self::readableOn([$seed], $colors);

        // The contrast surface is the second seed when given, else a deep shade of the first.
        $contrast = $secondary !== '' ? $secondary : Color::fromOklch(0.27, min($seedChroma, 0.1), $hue);
        [$contrastLightness, $contrastChroma, $contrastHue] = Color::toOklch($contrast);
        $colors['contrast'] = $contrast;
        $colors['on-contrast'] = self::readableOn([$contrast], $colors);
        $lightText = $colors['on-contrast'] === $colors['background'];
        $colors['muted-on-contrast'] = Color::fromOklch(
            $contrastLightness + ($lightText ? 0.42 : -0.42),
            min($contrastChroma, 0.04),
            $contrastHue,
        );
        $colors['contrast-raised'] = Color::fromOklch($contrastLightness + ($lightText ? 0.06 : -0.06), $contrastChroma, $contrastHue);

        $colors['gradient-start'] = $seed;
        $colors['gradient-end'] = Color::fromOklch(max(0.2, $seedLightness - 0.1), $seedChroma, $hue + 45);
        $colors['on-gradient'] = self::readableOn([$colors['gradient-start'], $colors['gradient-end']], $colors);

        return $colors;
    }

    /**
     * EVERY text/background pair the palette produces, with what it measures and what it
     * needs. The gauge on the Design screen is this list; failures() is a filter over it.
     *
     * One list, because two would drift: for a while the screen could only say "this fails",
     * which made a palette that passes by a hair look the same as one that passes easily —
     * and a person cannot aim at a number they are never shown.
     *
     * $decision names the choice responsible, so a failure can point at the control that
     * causes it rather than at the colour it produced.
     *
     * @param array<string, string> $colors
     * @return list<array{pair: string, decision: string, ratio: float, required: float, passes: bool, foreground: string, background: string}>
     */
    public static function pairs(array $colors, bool $hasSecondary): array
    {
        $contrastDecision = $hasSecondary ? 'secondary' : 'seed';
        $defined = [
            ['text_on_background', 'surface_contrast', 'text', 'background'],
            ['muted_on_background', 'surface_contrast', 'muted', 'background'],
            ['text_on_surface', 'surface_contrast', 'text', 'surface'],
            ['muted_on_surface', 'surface_contrast', 'muted', 'surface'],
            ['links_on_background', 'seed', 'link', 'background'],
            ['links_on_surface', 'seed', 'link', 'surface'],
            ['button_text_on_accent', 'seed', 'on-accent', 'accent'],
            ['text_on_contrast', $contrastDecision, 'on-contrast', 'contrast'],
            ['muted_on_contrast', $contrastDecision, 'muted-on-contrast', 'contrast'],
            ['text_on_gradient_start', 'seed', 'on-gradient', 'gradient-start'],
            ['text_on_gradient_end', 'seed', 'on-gradient', 'gradient-end'],
        ];

        $pairs = [];
        foreach ($defined as [$pair, $decision, $foreground, $background]) {
            $ratio = Color::contrast($colors[$foreground], $colors[$background]);
            $pairs[] = [
                'pair' => $pair,
                'decision' => $decision,
                'ratio' => $ratio,
                'required' => self::AA_BODY,
                'passes' => $ratio >= self::AA_BODY,
                // The two colours themselves, so the gauge can show the pair rather than
                // only name it: a row that says 3.9:1 and shows nothing is a number.
                'foreground' => $colors[$foreground],
                'background' => $colors[$background],
            ];
        }

        return $pairs;
    }

    /**
     * Every pair that falls below WCAG AA, with the decision responsible for it. What Save
     * refuses on, and what the screen puts beside the control at fault.
     *
     * @param array<string, string> $colors
     * @return list<array{pair: string, decision: string, ratio: float, required: float}>
     */
    public static function failures(array $colors, bool $hasSecondary): array
    {
        $failures = [];
        foreach (self::pairs($colors, $hasSecondary) as $pair) {
            if (!$pair['passes']) {
                $failures[] = ['pair' => $pair['pair'], 'decision' => $pair['decision'], 'ratio' => $pair['ratio'], 'required' => $pair['required']];
            }
        }

        return $failures;
    }

    /**
     * The palette's own light or dark text colour, whichever gives the higher worst-case
     * contrast across all of $backgrounds.
     *
     * @param non-empty-list<string> $backgrounds
     * @param array<string, string> $colors
     */
    private static function readableOn(array $backgrounds, array $colors): string
    {
        $worst = static function (string $text) use ($backgrounds): float {
            return min(array_map(static fn (string $background): float => Color::contrast($text, $background), $backgrounds));
        };

        return $worst($colors['background']) >= $worst($colors['text']) ? $colors['background'] : $colors['text'];
    }
}
