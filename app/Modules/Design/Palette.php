<?php

namespace App\Modules\Design;

/**
 * Derives the whole palette from one or two seed colours. Every text/background pair it
 * produces is measured against WCAG AA by PalettePairs.
 *
 * Seeds are used as given and never nudged to pass: the seed is the accent, the button
 * colour and the link colour, so a seed too light to read as text fails, naming the
 * pair, instead of being silently turned into a different colour.
 */
final class Palette
{
    /**
     * How far the tinted surface sits from the page background, in OKLCH lightness, at the
     * points of the 0–100 surface contrast the step's old names stood for; between them it is
     * interpolated (D-164).
     */
    private const SURFACE_CURVE = [0 => 0.0, 20 => 0.03, 50 => 0.065, 80 => 0.11, 100 => 0.14];

    /** The page's lightness in each mode, when no background is set by hand. */
    private const PAGE = ['light' => 0.99, 'dark' => 0.17];

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
    public const BY_HAND = ['background', 'card', 'surface', 'border', 'text', 'muted', 'link'];

    /**
     * The palette of a design with every key answered (Tokens::resolve()).
     *
     * @param array<string, string> $resolved
     * @return array<string, string>
     */
    public static function forDecisions(array $resolved): array
    {
        return self::colors($resolved['seed'], $resolved['secondary'], (float) $resolved['surface_contrast'], Tokens::byHand($resolved), $resolved['mode'] ?? 'light');
    }

    /** The surface step for a surface contrast of 0–100: straight lines between the points. */
    public static function surfaceStep(float $contrast): float
    {
        $contrast = max(0.0, min(100.0, $contrast));
        $from = 0.0;
        $step = 0.0;
        foreach (self::SURFACE_CURVE as $at => $to) {
            $at = (float) $at;
            if ($contrast <= $at) {
                return $at <= $from ? $to : $step + ($to - $step) * ($contrast - $from) / max(0.001, $at - $from);
            }
            $from = $at;
            $step = $to;
        }

        return $step;
    }

    /**
     * @param array<string, string> $byHand role => #rrggbb for a role the owner set, '' or
     *        absent for one the palette works out
     * @param string $mode light or dark (D-164): which way the page and its inks go
     * @return array<string, string> colour name => #rrggbb, emitted as --color-{name}
     */
    public static function colors(string $seed, string $secondary, float $surfaceContrast, array $byHand = [], string $mode = 'light'): array
    {
        [$seedLightness, $seedChroma, $hue] = Color::toOklch($seed);
        $step = self::surfaceStep($surfaceContrast);
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
         * DARK MODE IS THE SAME BRANCH (D-164): the page starts dark instead of near-white,
         * and every neutral and ink walks the other way, measured by the same pairs.
         */
        $page = self::PAGE[$mode] ?? self::PAGE['light'];
        if (($byHand['background'] ?? '') !== '') {
            [$page] = Color::toOklch($byHand['background']);
        }
        $dark = $page < 0.5;
        $away = static fn (float $by): float => max(0.0, min(1.0, $dark ? $page + $by : $page - $by));

        $colors = [
            'background' => Color::fromOklch($page, $tint * 0.4, $hue),
            // CARDS AND PANELS ARE THEIR OWN ROLE (D-067). They used to take the tinted
            // surface's colour on a plain section, which made a card on the page and a
            // tinted band the same tone — so a card sitting inside a tinted section had
            // nothing to be distinct from. It sits half a step from the page, between the
            // two.
            'card' => Color::fromOklch($away($step * 0.5), $tint * 0.7, $hue),
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
        $colors['contrast'] = $contrast;
        $inks = self::inksOn($contrast, $colors);
        $colors['on-contrast'] = $inks['text'];
        $colors['muted-on-contrast'] = $inks['muted'];
        $colors['contrast-raised'] = $inks['raised'];

        $colors['gradient-start'] = $seed;
        $colors['gradient-end'] = Color::fromOklch(max(0.2, $seedLightness - 0.1), $seedChroma, $hue + 45);
        $colors['on-gradient'] = self::readableOn([$colors['gradient-start'], $colors['gradient-end']], $colors);

        return $colors;
    }

    /**
     * THE INK FOR A SURFACE THE PALETTE DID NOT CHOOSE (PLAN.md D-076).
     *
     * Whichever of the palette's two inks can be read on it, a muted version of that, and a
     * raised version of the surface itself — the three a section needs beyond its background.
     *
     * WHICH INK WON IS MEASURED, NOT GUESSED FROM WHICH SLOT IT CAME FROM. This asked
     * whether the ink was the BACKGROUND colour and took that to mean "light text" — true
     * while every background was near-white, and wrong the moment one could be set by hand:
     * on a dark page the background IS the dark ink, and the muted text beside it was then
     * pushed the wrong way, to 1.40:1 (D-063).
     *
     * It was the contrast surface's own arithmetic, inline. The header and the footer may
     * now carry a colour of their own (D-076) and need exactly the same three, so it is one
     * function with three callers rather than three copies that would drift — the contrast
     * surface included, which is what proves the move changed nothing.
     *
     * @param array<string, string> $colors the palette so far; needs `background` and `text`
     * @return array{text: string, muted: string, raised: string}
     */
    public static function inksOn(string $surface, array $colors): array
    {
        [$lightness, $chroma, $hue] = Color::toOklch($surface);
        $text = self::readableOn([$surface], $colors);
        [$inkLightness] = Color::toOklch($text);
        $lightText = $inkLightness > 0.5;
        $raised = Color::fromOklch($lightness + ($lightText ? 0.06 : -0.06), $chroma, $hue);

        return [
            'text' => $text,
            // Read on the surface AND on a card raised from it (D-183): the muted words of a
            // card in a contrast band stood at 3.8–4.5:1 under all five characters.
            'muted' => self::muted([$surface, $raised], $lightness, min($chroma, 0.04), $hue, $lightText),
            'raised' => $raised,
        ];
    }

    /**
     * The muted ink for a surface: the step the contrast surface has always taken, and
     * further where that step is not enough to read (PLAN.md D-076).
     *
     * A FIXED STEP IS WRONG AT THE ENDS OF THE RANGE. 0.42 of lightness away is a comfortable
     * muted tone for a surface somewhere in the middle, which every contrast surface the five
     * characters ship is. Measured against a surface the OWNER picks it breaks at both ends:
     * a pure black header put the muted text at 2.48:1 and a pure white one at 4.29:1, so
     * Boxlet refused the two colours anybody is likeliest to choose. That was the derivation
     * being weak, not the choice being bad.
     *
     * It walks further away until it reads on every one of `$surfaces` — the surface and the
     * card raised from it, which is the darker of the two under light ink and the lighter
     * under dark (D-183) — and stops at the first step that does. A surface already passing at
     * 0.42 is returned at 0.42.
     *
     * When the whole range is exhausted nothing is forced: the last value is returned, the
     * pair fails and the check refuses it, naming the control (D-063). A colour neither of
     * the palette's inks can be read on is a colour Boxlet should say no to.
     */
    /** @param non-empty-list<string> $surfaces */
    private static function muted(array $surfaces, float $lightness, float $chroma, float $hue, bool $lighter): string
    {
        $limit = $lighter ? 1.0 : 0.0;
        $muted = Color::fromOklch($lightness + ($lighter ? 0.42 : -0.42), $chroma, $hue);
        for ($step = 0.42; $lighter ? $lightness + $step <= $limit : $lightness - $step >= $limit; $step += 0.02) {
            $muted = Color::fromOklch($lightness + ($lighter ? $step : -$step), $chroma, $hue);
            $worst = min(array_map(static fn (string $surface): float => Color::contrast($muted, $surface), $surfaces));
            if ($worst >= self::AA_BODY) {
                break;
            }
        }

        return $muted;
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
