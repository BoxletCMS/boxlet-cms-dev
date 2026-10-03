<?php

use App\Modules\Design\Characters;
use App\Modules\Design\Color;
use App\Modules\Design\DesignSet;
use App\Modules\Design\Palette;
use App\Modules\Design\Tokens;

/*
 * A SET'S DARK VERSION (PLAN.md D-185, the owner): an optional `dark` block of colours, surface
 * contrast and shadow strength that stands for the light ones in dark mode, a colour it leaves
 * out worked out by the palette, anything else it leaves out the light version's; and with it
 * both versions are checked for contrast as errors. sampleSet(), parseSet(), customFile() and
 * withCustomDesigns() are design_set_test.php's and characters_test.php's.
 */

test('a dark version is read, kept in order, and written back', function () {
    $raw = sampleSet();
    $raw['decisions']['color_background'] = '#fbf6ee';
    $raw['dark'] = ['shadow_strength' => 30, 'seed' => '#9ec5ff', 'color_background' => '#101820', 'wobble' => 'x'];
    $read = parseSet($raw);
    assertEquals([], $read['errors'], 'errors');
    assertContains('dark.wobble', implode(' | ', $read['warnings']), 'a key a dark version cannot hold, left out');
    assertEquals(['seed' => '#9ec5ff', 'color_background' => '#101820', 'shadow_strength' => '30'], $read['set']['dark'] ?? null, 'what it holds');
    $set = $read['set'] ?? fail('nothing read');
    $file = json_decode(DesignSet::export($set['id'], $set['name'], $set['description'], $set['decisions'], $set['look'], $set['composition'], '', [], $set['dark']), true);
    assertEquals(['seed' => '#9ec5ff', 'color_background' => '#101820', 'shadow_strength' => '30'], $file['dark'] ?? null, 'written in the vocabulary\'s order');
    assertEquals(null, json_decode(DesignSet::export($set['id'], $set['name'], [], $set['decisions'], $set['look'], null), true)['dark'] ?? null, 'and no block for none');
});

test('with a dark version, both versions are checked, the dark one named dark.', function () {
    $raw = sampleSet();
    $raw['dark'] = ['color_text' => '#303030'];
    $read = parseSet($raw);
    assertEquals(null, $read['set'], 'refused');
    assertContains('dark.color_text', implode(' | ', $read['errors']), 'the dark version\'s text on its page');

    // The light version is held to light mode with one, whatever mode the set opens in.
    $raw = sampleSet();
    $raw['decisions']['mode'] = 'dark';
    $raw['decisions']['color_text'] = '#202020';
    $raw['dark'] = ['color_text' => '#f0f0f0'];
    assertEquals([], parseSet($raw)['errors'], 'dark text is the light version\'s, light text the dark one\'s');
});

test('in dark mode a character\'s dark version stands for its light one', function () {
    $file = customFile('harbour', [
        'decisions' => ['color_background' => '#fbf6ee', 'secondary' => '#f1e3c6'],
        'dark' => ['seed' => '#9ec5ff', 'surface_contrast' => '60'],
    ]);
    withCustomDesigns(['harbour.json' => $file], function (): void {
        assertEquals(['seed' => '#9ec5ff', 'surface_contrast' => '60'], Characters::dark('harbour'), 'the dark version');
        $light = Tokens::resolve([], 'harbour');
        assertEquals('#1d3557', $light['seed'], 'light mode: the light seed');
        assertEquals('#fbf6ee', $light['color_background'], 'and the light page by hand');

        $dark = Tokens::resolve(['mode' => 'dark'], 'harbour');
        assertEquals('#9ec5ff', $dark['seed'], 'dark mode: the dark seed');
        assertEquals('60', $dark['surface_contrast'], 'its surface contrast');
        assertEquals('', $dark['color_background'], 'a colour it leaves out is the palette\'s, not the light page carried over');
        assertEquals('#f1e3c6', $dark['secondary'], 'anything else it leaves out is the light version\'s');
        assertTrue(Color::toOklch(Palette::forDecisions($dark)['background'])[0] < 0.5, 'and the page is dark');

        // The owner's own value holds in both modes (until the owner's model decides otherwise).
        assertEquals('#123456', Tokens::resolve(['mode' => 'dark', 'seed' => '#123456'], 'harbour')['seed'], 'the owner\'s seed');
    });
});
