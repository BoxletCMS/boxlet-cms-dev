<?php

use App\Core\Blocks;
use App\Modules\Design\Color;
use App\Modules\Design\Palette;
use App\Modules\Design\Presets;

/*
 * THE HERO WITH ITS PICTURE BEHIND THE WORDS (PLAN.md D-118).
 */

/**
 * @param array<string, mixed> $content
 * @param array<int, array{id: int, filename: string, width: int, height: int, focalX: int, focalY: int, variants: array<string, array{width: int, height: int, formats: list<string>}>, alt: string, version: string}> $media
 */
function coverHero(array $content, string $layout, array $media = []): string
{
    static $registry = null;
    $registry ??= Blocks::discover(dirname(__DIR__) . '/app/Blocks');

    return $registry->render('hero', $registry->normalize('hero', $content), [], $layout, $media, false, 'none', [], 'en');
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
 * EVERY STRENGTH KEEPS THE WORDS READABLE OVER ANY PICTURE, under every character.
 *
 * The words are the contrast surface's own ink, and between them and the photograph is the
 * contrast colour at the veil's opacity. The worst photograph is the one as light as the
 * ink, or as dark: a pure white or a pure black under the veil. So the test is those two,
 * at the weakest strength the stylesheet ships, read from the stylesheet itself — measured
 * when this was written, 0.55 left Minimal at 3.5:1 and 0.65 is the first to clear 4.5:1
 * everywhere (4.7:1).
 */
test('the weakest veil keeps the words at 4.5:1 over a white or a black picture, under every character', function (): void {
    $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/blocks-hero.css');
    preg_match_all('~--hero-veil:\s*([0-9.]+)\s*;~', $css, $found);
    $strengths = array_map('floatval', $found[1]);
    if ($strengths === []) {
        fail('blocks-hero.css declares no --hero-veil at all');
    }
    assertEquals(3, count($strengths), 'three strengths, one per choice');
    $weakest = min($strengths);

    foreach (Presets::names() as $name) {
        $design = Presets::get($name);
        $colors = Palette::colors((string) $design['seed'], (string) $design['secondary'], (string) ($design['surface_contrast'] ?? 'normal'));
        foreach (['#ffffff', '#000000'] as $picture) {
            $ratio = Color::contrast($colors['on-contrast'], veiled($colors['contrast'], $picture, $weakest));
            assertTrue($ratio >= 4.5, sprintf('%s over %s at %.2f is %.2f:1', $name, $picture, $weakest, $ratio));
        }
    }
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
    $content = ['heading' => 'Welcome', 'image' => 7, 'height' => 'tall', 'veil' => 'strong'];

    $cover = coverHero($content, 'cover-low', $media);
    assertContains('class="hero is-cover height-tall veil-strong"', $cover, 'the choices reach the markup as classes');
    assertContains('<div class="hero-cover-picture">', $cover, 'the picture layer');
    assertContains('/m/hero/7-harbour', $cover, 'the picture, from the full-width presets');
    assertContains('alt="The harbour at dusk"', $cover, 'it is content, so it keeps its alt');
    assertTrue(!str_contains($cover, 'hero-media'), 'a cover hero also drew the picture beside the words');

    // Every cover arrangement is one: the fourth, on the right, came after the first three.
    foreach (['cover-center', 'cover-left', 'cover-right', 'cover-low'] as $arrangement) {
        assertContains('hero is-cover', coverHero($content, $arrangement, $media), "{$arrangement} is not a cover");
    }

    // No picture yet: the layer is still drawn, because its colour is what the words are
    // set for.
    $empty = coverHero(['heading' => 'Welcome'], 'cover-center');
    assertContains('<div class="hero-cover-picture">', $empty, 'an empty cover hero lost its surface');

    // The arrangements that were there before are untouched by the two new choices.
    $left = coverHero($content, 'left', $media);
    assertTrue(!str_contains($left, 'is-cover') && !str_contains($left, 'hero-cover-picture'), 'a left hero became a cover');
    assertTrue(!str_contains($left, 'height-') && !str_contains($left, 'veil-'), 'a left hero carries the cover choices');
});

test('a hero stored before the cover arrangements reads its new choices as the first of each', function (): void {
    $registry = Blocks::discover(dirname(__DIR__) . '/app/Blocks');
    $old = $registry->normalize('hero', ['heading' => 'Welcome', 'subheading' => '', 'image' => null]);

    assertEquals('content', $old['height'] ?? null, 'height');
    assertEquals('light', $old['veil'] ?? null, 'veil');
});
