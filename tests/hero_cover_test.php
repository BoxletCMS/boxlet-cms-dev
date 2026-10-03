<?php

use App\Core\Blocks;
use App\Modules\Design\Color;
use App\Modules\Design\Palette;
use App\Modules\Design\PaletteInks;
use App\Modules\Design\Presets;

/*
 * THE HERO WITH ITS PICTURE BEHIND THE WORDS (PLAN.md D-118).
 */

/**
 * @param array<string, mixed> $content
 * @param array<int, array{id: int, filename: string, width: int, height: int, focalX: int, focalY: int, variants: array<string, array{width: int, height: int, formats: list<string>}>, alt: string, version: string}> $media
 * @param array<string, string> $options the block's options (D-166)
 */
function coverHero(array $content, string $layout, array $media = [], array $options = []): string
{
    static $registry = null;
    $registry ??= Blocks::discover(dirname(__DIR__) . '/app/Blocks');

    return $registry->render('hero', $registry->normalize('hero', $content), [], $layout, $media, false, 'none', [], 'en', [], $options);
}

/** One sRGB colour laid over another at an opacity, as a browser composites a veil. */
function veiled(string $veil, string $under, float $opacity): string
{
    $channels = [];
    for ($i = 0; $i < 3; $i++) {
        $channels[] = (int) round(hexdec(substr($veil, 1 + 2 * $i, 2)) * $opacity + hexdec(substr($under, 1 + 2 * $i, 2)) * (1 - $opacity));
    }

    return sprintf('#%02x%02x%02x', ...$channels);
}

/*
 * EVERY VEIL KEEPS THE WORDS READABLE OVER ANY PICTURE (O-33, D-183), under every character in
 * both modes, and under colours a set may bring.
 *
 * The words are the contrast surface's own ink, and between them and the photograph is the
 * contrast colour at the veil's opacity. The worst photograph is the one as light as the
 * ink, or as dark: a pure white or a pure black under the veil. The least veil is worked out
 * from the palette (PaletteInks::veil) and is measured here with this file's own compositing, not
 * the palette's. A fixed 0.65 held only for the five characters in light mode: Minimal in dark
 * mode needs 0.68, and a band with a picture wore 0.55, 3.1-3.8:1 under four of five.
 */
test('the least veil keeps the words at 4.5:1 over a white or a black picture, under every character and odd colours', function (): void {
    $designs = [];
    foreach (Presets::names() as $name) {
        foreach (['light', 'dark'] as $mode) {
            $designs["{$name} {$mode}"] = ['mode' => $mode] + Presets::get($name);
            $designs["{$name} {$mode}"]['mode'] = $mode;
        }
    }
    foreach (['#808080', '#ff0000', '#00c853', '#ffe600', '#0000ff', '#5a5a5a'] as $secondary) {
        $designs["secondary {$secondary}"] = ['secondary' => $secondary] + Presets::get('minimal');
        $designs["secondary {$secondary}"]['secondary'] = $secondary;
    }
    $worse = [];
    foreach ($designs as $label => $design) {
        $colors = Palette::forDecisions($design);
        $least = PaletteInks::veil($colors);
        assertTrue($least >= 0.55 && $least <= 1.0, "{$label}: {$least}");
        foreach (['#ffffff', '#000000'] as $picture) {
            $ratio = Color::contrast($colors['on-contrast'], veiled($colors['contrast'], $picture, $least));
            // At 1.0 the picture is gone, and the pair is the contrast surface's own, which
            // the check refuses on by itself (text_on_contrast).
            if ($ratio < 4.5 && $least < 1.0) {
                $worse[] = sprintf('%s over %s at %.2f: %.2f:1', $label, $picture, $least, $ratio);
            }
        }
    }
    assertEquals([], $worse, 'words under 4.5:1 over a picture');
});

test('a band with a picture and every cover strength take the least veil, never less', function (): void {
    $hero = (string) file_get_contents(dirname(__DIR__) . '/public/assets/blocks-hero.css');
    preg_match_all('~--hero-veil:\s*([^;]+);~', $hero, $strengths);
    assertEquals(3, count($strengths[1]), 'three strengths, one per choice');
    foreach ($strengths[1] as $value) {
        assertTrue(preg_match('~^max\(0\.\d+, var\(--veil-least, 0\.\d+\)\)$~', trim($value)) === 1, "a strength not held to the least: {$value}");
    }
    $sections = (string) file_get_contents(dirname(__DIR__) . '/public/assets/sections.css');
    assertContains('--section-picture-veil: var(--veil-least, 0.55);', $sections, 'a band with a picture');
    $css = (new App\Modules\Design\TokenCompiler())->css(App\Modules\Design\Derived::from(Presets::get('minimal')));
    assertContains('--veil-least: 0.64;', $css, 'the token, Minimal\'s in light mode');
});

test('a cover arrangement draws the picture behind the words, and the others draw none', function (): void {
    $variants = [];
    foreach (['wide' => [1200, 630], 'hero' => [1920, 1080], 'full' => [2400, 1600]] as $preset => [$w, $h]) {
        $variants[$preset] = ['width' => $w, 'height' => $h, 'formats' => ['webp', 'jpg']];
    }
    $media = [7 => [
        'id' => 7, 'filename' => 'harbour', 'width' => 2400, 'height' => 1600, 'focalX' => 50, 'focalY' => 50,
        'variants' => $variants, 'alt' => 'The harbour at dusk', 'version' => '',
    ]];
    $content = ['heading' => 'Welcome', 'image' => 7];
    // Options since D-166: how it is presented, apart from what it says.
    $options = ['height' => 'tall', 'veil' => 'strong'];

    $cover = coverHero($content, 'cover-low', $media, $options);
    assertContains('class="hero height-tall is-cover veil-strong"', $cover, 'the choices reach the markup as classes');
    assertContains('<div class="hero-cover-picture">', $cover, 'the picture layer');
    assertContains('/m/hero/7-harbour', $cover, 'the picture, from the full-width presets');
    assertContains('alt="The harbour at dusk"', $cover, 'it is content, so it keeps its alt');
    assertTrue(!str_contains($cover, 'hero-media'), 'a cover hero also drew the picture beside the words');

    // Every cover arrangement is one: the fourth, on the right, came after the first three.
    foreach (['cover-center', 'cover-left', 'cover-right', 'cover-low'] as $arrangement) {
        assertContains('is-cover', coverHero($content, $arrangement, $media, $options), "{$arrangement} is not a cover");
    }

    // No picture yet: the layer is still drawn, because its colour is what the words are
    // set for.
    $empty = coverHero(['heading' => 'Welcome'], 'cover-center');
    assertContains('<div class="hero-cover-picture">', $empty, 'an empty cover hero lost its surface');

    // The other arrangements draw no cover and no veil. THE HEIGHT CHANGED DELIBERATELY with
    // D-166: it is every hero's option now (README 1.6), so a left hero stands tall too.
    $left = coverHero($content, 'left', $media, $options);
    assertTrue(!str_contains($left, 'is-cover') && !str_contains($left, 'hero-cover-picture'), 'a left hero became a cover');
    assertTrue(!str_contains($left, 'veil-'), 'a left hero carries the veil');
    assertContains('class="hero height-tall"', $left, 'and its height is its own');
});

// Options with nothing said are their defaults (D-166): as tall as the words, the lightest veil.
test('a hero whose options nobody set is drawn with their defaults', function (): void {
    assertContains('class="hero height-auto is-cover veil-light"', coverHero(['heading' => 'Welcome'], 'cover-center'), 'the defaults');
    assertContains('class="hero height-screen is-cover veil-light"', coverHero(['heading' => 'Welcome'], 'cover-center', [], ['height' => 'screen', 'veil' => 'neon']), 'a value no option holds is its default');
});
