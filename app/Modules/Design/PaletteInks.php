<?php

namespace App\Modules\Design;

/**
 * THE INKS THAT MUST BE READ (PLAN.md D-076, D-183, D-184): which of the palette's two inks
 * reads on a colour, the muted ink for a surface and its cards, the main colour lifted on a
 * dark page, and the least veil over a picture. Each walks a colour in OKLCH until a pair
 * reads at 4.5:1, and stops at the first step that does.
 *
 * Split from Palette (D-184), which had grown past 300 lines: Palette names the colours a
 * design is made of, this works out the ones that exist only to be readable.
 */
final class PaletteInks
{
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
            if ($worst >= Palette::AA_BODY) {
                break;
            }
        }

        return $muted;
    }

    /**
     * `$color` walked up in OKLCH lightness, its hue and as much of its chroma as the gamut
     * allows kept, to the first step that reads at 4.5:1 on every one of `$grounds`; itself
     * when it already does. At the top of the range it is as light as it goes, and the check
     * refuses what still fails.
     *
     * @param non-empty-list<string> $grounds
     */
    public static function lifted(string $color, array $grounds): string
    {
        $worst = static fn (string $c): float => min(array_map(static fn (string $g): float => Color::contrast($c, $g), $grounds));
        if ($worst($color) >= Palette::AA_BODY) {
            return $color;
        }
        [$lightness, $chroma, $hue] = Color::toOklch($color);
        $lifted = $color;
        for ($at = $lightness + 0.02; $at <= 1.0; $at += 0.02) {
            $lifted = Color::fromOklch($at, $chroma, $hue);
            if ($worst($lifted) >= Palette::AA_BODY) {
                break;
            }
        }

        return $lifted;
    }

    /**
     * THE LEAST VEIL OVER A PICTURE (O-33, D-183): how much of the contrast colour must lie
     * between a photograph and the words on it for the words to read at 4.5:1 whatever the
     * photograph is. The worst photographs are a pure white and a pure black; the veil is
     * composited over them as a browser composites opacity, channel by channel in sRGB.
     *
     * Never under 0.55, the veil every band with a picture has worn since D-024; above it only
     * as far as the colours ask. At 1.0 the picture is gone and the pair is the contrast
     * surface's own text, which the check measures and refuses on (text_on_contrast).
     *
     * @param array<string, string> $colors needs `contrast` and `on-contrast`
     */
    public static function veil(array $colors): float
    {
        for ($opacity = 0.55; $opacity < 1.0; $opacity = round($opacity + 0.01, 2)) {
            $worst = min(array_map(
                static fn (string $picture): float => Color::contrast($colors['on-contrast'], self::over($colors['contrast'], $picture, $opacity)),
                ['#ffffff', '#000000'],
            ));
            if ($worst >= Palette::AA_BODY) {
                return $opacity;
            }
        }

        return 1.0;
    }

    /** `$veil` at `$opacity` over `$under`, as #rrggbb. */
    public static function over(string $veil, string $under, float $opacity): string
    {
        $channels = [];
        for ($i = 0; $i < 3; $i++) {
            $channels[] = (int) round(hexdec(substr($veil, 1 + 2 * $i, 2)) * $opacity + hexdec(substr($under, 1 + 2 * $i, 2)) * (1 - $opacity));
        }

        return sprintf('#%02x%02x%02x', ...$channels);
    }

    /**
     * The palette's own light or dark text colour, whichever gives the higher worst-case
     * contrast across all of $backgrounds.
     *
     * @param non-empty-list<string> $backgrounds
     * @param array<string, string> $colors
     */
    public static function readableOn(array $backgrounds, array $colors): string
    {
        $worst = static function (string $text) use ($backgrounds): float {
            return min(array_map(static fn (string $background): float => Color::contrast($text, $background), $backgrounds));
        };

        return $worst($colors['background']) >= $worst($colors['text']) ? $colors['background'] : $colors['text'];
    }
}
