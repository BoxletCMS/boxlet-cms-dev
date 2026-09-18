<?php

// The page as a sheet (PLAN.md D-031, SPEC §5.4): the header's width, the boxed inset, and
// the colour around a boxed page.
//
// THE POINT OF THESE TESTS IS THE CONTRAST GUARANTEE. A new colour would normally have to
// join Palette::failures(), because every colour that can sit under text is checked at
// WCAG AA. The page background is exempt for one reason only: no text ever sits on it. That
// is a claim about geometry, so it is asserted here rather than written down and trusted —
// if a later change lets a section escape the sheet, this fails instead of the palette
// quietly ceasing to mean anything.

use App\Modules\Design\Palette;
use App\Modules\Design\Presets;
use App\Modules\Design\TokenCompiler;
use App\Modules\Design\Tokens;

test('the three page decisions are closed sets, and every value has a label', function (): void {
    foreach (['header_width', 'boxed', 'page_background'] as $key) {
        $values = Tokens::choices()[$key] ?? [];
        assertTrue($values !== [], "{$key} is not offered as a decision");
        foreach ($values as $value) {
            $label = t("design.{$key}.{$value}");
            assertTrue($label !== "design.{$key}.{$value}", "no label for design.{$key}.{$value}");
        }
    }
});

test('every character answers all three, so a character stays a complete set', function (): void {
    foreach (Presets::names() as $name) {
        $preset = Presets::get($name);
        foreach (['header_width', 'boxed', 'page_background'] as $key) {
            assertTrue(isset($preset[$key]), "{$name} does not say what {$key} is");
            assertTrue(
                in_array($preset[$key], Tokens::choices()[$key], true),
                "{$name} sets {$key} to {$preset[$key]}, which is not one of its values",
            );
        }
    }
});

/*
 * A design saved before these decisions existed has nine rows, not twelve. Every install in
 * the wild is in that state until its owner next saves, so loading one must not be an error
 * — it takes the default character's answers.
 */
test('a design saved before these decisions loads without error', function (): void {
    $old = Presets::get(Presets::DEFAULT);
    unset($old['header_width'], $old['boxed'], $old['page_background']);

    $loaded = Tokens::validate($old + Presets::get(Presets::DEFAULT));
    assertEquals([], $loaded['errors'], 'an older decision set reported errors');
    foreach (['header_width', 'boxed', 'page_background'] as $key) {
        assertTrue(isset($loaded['decisions'][$key]), "{$key} was not filled in");
    }
});

test('an unboxed page has no frame at all, so nothing surrounds it', function (): void {
    $unboxed = Tokens::derive(Tokens::validate(['boxed' => 'no'] + Presets::get('minimal'))['decisions']);
    assertEquals('0', $unboxed['page']['frame'], 'frame with boxed: no');

    $boxed = Tokens::derive(Tokens::validate(['boxed' => 'yes'] + Presets::get('soft'))['decisions']);
    assertTrue($boxed['page']['frame'] !== '0', 'a boxed page was given no frame');
});

/*
 * The exemption, stated as a test. The page background is one of the palette's own colours,
 * so if a future change ever does put text on it, the pair it would need is already
 * derivable — but the design says it cannot happen, and this is where that is checked.
 */
test('the page background is a palette colour, never a free one', function (): void {
    foreach (Presets::names() as $name) {
        $decisions = Tokens::validate(Presets::get($name))['decisions'];
        $colors = Palette::colors($decisions['seed'], $decisions['secondary'], $decisions['surface_contrast']);
        $derived = Tokens::derive($decisions);

        assertTrue(
            in_array($derived['page']['bg'], $colors, true),
            "{$name}'s page background {$derived['page']['bg']} is not one of the palette's colours",
        );
        assertEquals($colors['background'], $derived['page']['sheet'], "{$name}: the sheet is not the page background");
    }
});

test('the page tokens compile under every character', function (): void {
    $compiler = new TokenCompiler();
    foreach (Presets::names() as $name) {
        $css = $compiler->css(Tokens::derive(Tokens::validate(Presets::get($name))['decisions']));
        foreach (['--page-bg', '--page-frame', '--page-sheet', '--page-header-width'] as $token) {
            assertContains($token . ':', $css, "{$name}: {$token} is missing from the compiled stylesheet");
        }
    }
});

test('the header takes its own width, not the content\'s', function (): void {
    $content = Tokens::derive(Tokens::validate(['header_width' => 'content'] + Presets::get('editorial'))['decisions']);
    assertEquals($content['container']['width'], $content['page']['header-width'], 'header_width: content');

    $full = Tokens::derive(Tokens::validate(['header_width' => 'full'] + Presets::get('editorial'))['decisions']);
    assertEquals('100%', $full['page']['header-width'], 'header_width: full');
});
