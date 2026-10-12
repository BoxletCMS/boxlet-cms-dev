<?php

use App\Modules\Design\Characters;
use App\Modules\Design\Color;
use App\Modules\Design\Derived;
use App\Modules\Design\Presets;
use App\Modules\Design\TokenCompiler;

// THE GREEN OF "SENT" (PLAN.md D-223, the owner): a form's thank-you in green, in a thin green
// frame on a tenth of the green over the section's ground. The palette has no green of its
// own, so Derived works one out per site; what is measured here is what a visitor reads: the
// words on the frame's tint, on the page, the tinted surface and the contrast surface, under
// every core design set, light and dark.

test('the green of a sent form reads at 4.5:1 on its frame, under every design set, light and dark', function () {
    // As CSS's color-mix(in srgb, ink 10%, ground) mixes it.
    $tint = static function (string $ink, string $ground): string {
        $a = array_map('hexdec', str_split(substr(ltrim($ink, '#'), 0, 6), 2));
        $b = array_map('hexdec', str_split(substr(ltrim($ground, '#'), 0, 6), 2));

        return '#' . implode('', array_map(static fn (int|float $x, int|float $y): string => sprintf('%02x', (int) round($x * 0.1 + $y * 0.9)), $a, $b));
    };
    $low = [];
    foreach (Characters::CORE as $set) {
        foreach (['light', 'dark'] as $mode) {
            $decisions = Presets::get($set);
            $decisions['mode'] = $mode;
            $colors = Derived::from($decisions)['color'];
            foreach ([['success', 'background'], ['success', 'surface'], ['success-on-contrast', 'contrast']] as [$green, $ground]) {
                $ratio = Color::contrast($colors[$green], $tint($colors[$green], $colors[$ground]));
                if ($ratio < 4.5) {
                    $low[] = "{$set} {$mode}: {$green} on {$ground} " . round($ratio, 2);
                }
            }
            // A green, not the text's ink it falls back to where none reads.
            [, $chroma, $hue] = Color::toOklch($colors['success']);
            assertTrue($chroma > 0.05 && $hue > 120 && $hue < 170, "{$set} {$mode}: the success colour is no green ({$colors['success']})");
        }
    }
    assertEquals([], $low, 'under 4.5:1');
});

test('the greens are design tokens, and a section names the one for its surface', function () {
    $css = (new TokenCompiler())->css(Derived::from(Presets::get('couture')));
    assertContains('--color-success:', $css, 'the page\'s green');
    assertContains('--color-success-on-contrast:', $css, 'the contrast surface\'s');
    $sections = (string) file_get_contents(dirname(__DIR__) . '/public/assets/sections.css');
    assertContains('--section-success: var(--color-success-on-contrast);', $sections, 'a contrast or picture section takes its own');
    $blocks = (string) file_get_contents(dirname(__DIR__) . '/public/assets/blocks.css');
    assertContains('color: var(--section-success);', $blocks, 'the words in green');
    assertContains('solid var(--section-success);', $blocks, 'the frame');
});
