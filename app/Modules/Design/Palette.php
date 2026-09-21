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
     * THE SIX ROLES AN OWNER MAY SET BY HAND (PLAN.md D-063), and no others.
     *
     * These are the INDEPENDENT ones: the page's own colours and the ink on them. Everything
     * else is either already theirs — the accent and the contrast surface are the two seeds —
     * or is COMPUTED FOR READABILITY and must stay computed. `on-accent`, `on-contrast`,
     * `muted-on-contrast`, `contrast-raised` and `on-gradient` are the palette choosing which
     * of two inks can be read on a colour; handing those over would be handing over the one
     * decision that keeps text legible, dressed as a choice.
     */
    public const BY_HAND = ['background', 'surface', 'border', 'text', 'muted', 'link'];

    /**
     * @param array<string, string> $byHand role => #rrggbb for a role the owner set, '' or
     *        absent for one the palette works out
     * @return array<string, string> colour name => #rrggbb, emitted as --color-{name}
     */
    public static function colors(string $seed, string $secondary, string $surfaceContrast, array $byHand = []): array
    {
        [$seedLightness, $seedChroma, $hue] = Color::toOklch($seed);
        $step = self::SURFACE_STEPS[$surfaceContrast] ?? self::SURFACE_STEPS['low'];
        // Neutrals carry a trace of the seed's hue, so greys belong to the palette.
        $tint = min($seedChroma, 0.14) * 0.1;
        $ink = min($seedChroma, 0.08) * 0.35;

        /*
         * THE NEUTRALS FOLLOW THE PAGE, not an assumption about it (D-063).
         *
         * They used to be fixed lightnesses — a near-white page, a near-black text — which
         * was true of every palette the seed could produce. A background SET BY HAND can be
         * dark, and against a dark page those numbers give grey text on black and a tinted
         * surface lighter than nothing: measured at 2.6:1 for muted text, which the check
         * then refuses. The page's own lightness decides the direction, so setting one
         * colour gives a coherent palette rather than a list of refusals.
         *
         * Every character leaves the background alone, so all five are untouched by this.
         */
        $page = 0.99;
        if (($byHand['background'] ?? '') !== '') {
            [$page] = Color::toOklch($byHand['background']);
        }
        $dark = $page < 0.5;
        $away = static fn (float $by): float => max(0.0, min(1.0, $dark ? $page + $by : $page - $by));

        $colors = [
            'background' => Color::fromOklch($page, $tint * 0.4, $hue),
            'surface' => Color::fromOklch($away($step), $tint, $hue),
            'border' => Color::fromOklch($away($step + 0.1), $tint * 1.5, $hue),
            'text' => Color::fromOklch($dark ? 0.95 : 0.2, $ink, $hue),
            'muted' => Color::fromOklch($dark ? 0.72 : 0.45, $ink, $hue),
            'accent' => $seed,
            'link' => $seed,
        ];
        /*
         * THE OWNER'S COLOURS GO IN HERE, BEFORE ANYTHING DEPENDS ON THEM.
         *
         * This is the whole reason this round was left until last. `on-accent`,
         * `on-contrast` and `on-gradient` are worked out FROM the background and the text —
         * apply a hand-set background after them and they are answers to a question nobody
         * asked any more: still legible against the colour that has gone, and possibly
         * unreadable against the one that arrived. Overriding first makes a stale dependent
         * colour impossible rather than unlikely.
         */
        foreach (self::BY_HAND as $role) {
            if (($byHand[$role] ?? '') !== '') {
                $colors[$role] = $byHand[$role];
            }
        }

        $colors['on-accent'] = self::readableOn([$seed], $colors);

        // The contrast surface is the second seed when given, else a deep shade of the first.
        $contrast = $secondary !== '' ? $secondary : Color::fromOklch(0.27, min($seedChroma, 0.1), $hue);
        [$contrastLightness, $contrastChroma, $contrastHue] = Color::toOklch($contrast);
        $colors['contrast'] = $contrast;
        $colors['on-contrast'] = self::readableOn([$contrast], $colors);
        /*
         * WHICH INK WON, MEASURED RATHER THAN GUESSED FROM WHICH SLOT IT CAME FROM.
         *
         * This asked whether the ink was the BACKGROUND colour, and took that to mean "light
         * text" — true while every background was near-white, and wrong the moment one could
         * be set by hand: on a dark page the background IS the dark ink, and the muted text
         * beside it was then pushed the wrong way, to 1.40:1 (D-063).
         */
        [$inkLightness] = Color::toOklch($colors['on-contrast']);
        $lightText = $inkLightness > 0.5;
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
     * @param array<string, string> $byHand the roles the owner set, so a failure names the
     *        control that can fix it
     * @return list<array{pair: string, decision: string, ratio: float, required: float, passes: bool, foreground: string, background: string}>
     */
    public static function pairs(array $colors, bool $hasSecondary, array $byHand = []): array
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
                // A COLOUR SET BY HAND OWNS ITS OWN FAILURE. Otherwise "text on the
                // background is 2.1:1" would point at surface contrast, a control that
                // cannot fix it, while the control that can sits two fields above. The ink
                // is named first, because it is usually the one to move.
                'decision' => self::responsible($foreground, $background, $byHand) ?? $decision,
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
     * @param array<string, string> $byHand
     * @return list<array{pair: string, decision: string, ratio: float, required: float}>
     */
    public static function failures(array $colors, bool $hasSecondary, array $byHand = []): array
    {
        $failures = [];
        foreach (self::pairs($colors, $hasSecondary, $byHand) as $pair) {
            if (!$pair['passes']) {
                $failures[] = ['pair' => $pair['pair'], 'decision' => $pair['decision'], 'ratio' => $pair['ratio'], 'required' => $pair['required']];
            }
        }

        return $failures;
    }

    /**
     * Which control a failing pair belongs to, when one of its two colours was set by hand.
     *
     * @param array<string, string> $byHand
     */
    private static function responsible(string $foreground, string $background, array $byHand): ?string
    {
        foreach ([$foreground, $background] as $role) {
            if (in_array($role, self::BY_HAND, true) && ($byHand[$role] ?? '') !== '') {
                return 'color_' . $role;
            }
        }

        return null;
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
