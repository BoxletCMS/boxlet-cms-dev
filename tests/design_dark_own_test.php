<?php

use App\Modules\Appearance\AppearanceForm;
use App\Modules\Appearance\DesignLibrary;
use App\Modules\Design\Design;
use App\Modules\Design\Tokens;

/*
 * THE OWNER'S COLOURS IN DARK MODE (PLAN.md D-187, the owner's model): one colour set by hand
 * holds for both modes; a colour set while Appearance is in Dark is the owner's for dark mode
 * only. In dark mode: the owner's dark colour, else the one held for both modes, else the set's
 * dark version, else the palette's. customFile() and withCustomDesigns() are
 * characters_test.php's; adminSite() pages_admin_test.php's.
 */

test('in dark mode the owner\'s dark colour stands over everything, and light mode never sees it', function () {
    $file = customFile('harbour', [
        'decisions' => ['color_background' => '#fbf6ee'],
        'dark' => ['color_background' => '#101820', 'color_text' => '#e8e2d8'],
    ]);
    withCustomDesigns(['harbour.json' => $file], function (): void {
        $own = ['color_background' => '#0a0a0a'];
        assertEquals('#101820', Tokens::resolve(['mode' => 'dark'], 'harbour')['color_background'], 'the set\'s dark version over the palette');
        assertEquals('#202020', Tokens::resolve(['mode' => 'dark', 'color_background' => '#202020'], 'harbour')['color_background'], 'a colour held for both modes over the set\'s dark');
        assertEquals('#0a0a0a', Tokens::resolve(['mode' => 'dark', 'color_background' => '#202020'], 'harbour', $own)['color_background'], 'the owner\'s dark colour over both');
        assertEquals('#e8e2d8', Tokens::resolve(['mode' => 'dark'], 'harbour', $own)['color_text'], 'a role the owner did not touch: the set\'s dark');
        assertEquals('#fbf6ee', Tokens::resolve([], 'harbour', $own)['color_background'], 'light mode keeps its own');
        assertEquals('#202020', Tokens::resolve(['color_background' => '#202020'], 'harbour', $own)['color_background'], 'and the colour held for both');
    });
    assertEquals('', Tokens::darkOwn(['color_text' => 'red', 'seed' => '#123456'])['color_text'], 'what is no colour is none');
    assertTrue(!array_key_exists('seed', Tokens::darkOwn(['seed' => '#123456'])), 'and only colours by hand may have one');
    assertEquals('#334455', Tokens::darkOwn(['header_colour' => '#334455'])['header_colour'], 'the header\'s own colour is one');
    assertEquals('#334455', Tokens::resolve(['mode' => 'dark', 'header_colour' => '#ffffff'], '', ['header_colour' => '#334455'])['header_colour'], 'and stands in dark mode');
});

testBothDrivers('the owner\'s dark colours are stored apart, kept by a save that knows nothing of them, and drawn', function (string $driver) {
    $db = adminSite($driver);
    $cache = sys_get_temp_dir();
    Design::save($db, ['mode' => 'dark', 'color_text' => '#eeeeee'], $cache, ['color_text' => '#f5e9d0']);
    assertEquals('#f5e9d0', Design::dark($db)['color_text'], 'stored');
    assertEquals('#eeeeee', Design::load($db)['color_text'], 'beside the colour held for both modes');
    assertEquals('#f5e9d0', Design::resolved($db)['color_text'], 'and drawn in dark mode');

    // The header's look saved alone does not know of them, and keeps them.
    Design::store($db, Design::load($db));
    assertEquals('#f5e9d0', Design::dark($db)['color_text'], 'kept');
    Design::store($db, Design::load($db), []);
    assertEquals('', Design::dark($db)['color_text'], 'and given none, none');
});

test('the form reads a dark colour only with its switch, and carries it to the preview', function () {
    $dark = AppearanceForm::dark(['dark_color_text' => '#f5e9d0', 'dark_color_text_on' => '1', 'dark_color_link' => '#ffcc00']);
    assertEquals('#f5e9d0', $dark['color_text'], 'switched on');
    assertEquals('', $dark['color_link'], 'a colour input always carries a colour; without its switch it is none');

    $query = AppearanceForm::query(['decisions' => [], 'look' => [], 'dark' => $dark], 'en', 'minimal');
    assertEquals('#f5e9d0', $query['dark_color_text'] ?? null, 'the preview asks with it');
    assertEquals('1', $query['dark_color_text_on'] ?? null, 'and its switch');
});

testBothDrivers('Appearance draws each palette role twice, its dark row saying when it is dark only, and "Use light value" gives it up', function (string $driver) {
    $db = adminSite($driver);
    Design::save($db, ['mode' => 'dark'], sys_get_temp_dir(), ['color_text' => '#f5e9d0']);
    $html = dispatch('/admin/appearance')->body;
    assertContains('name="dark_color_text" value="#f5e9d0"', $html, 'the owner\'s dark colour');
    assertContains('name="dark_color_text_on" value="1" checked', $html, 'switched on');
    assertContains('value="colour:light:text"', $html, 'the way back to the light value');
    assertContains('name="dark_color_link"', $html, 'a role with none has its dark row too');
    assertContains(e(t('design.dark.only')), $html, 'said');
    assertContains('name="dark_header_colour"', $html, 'the header\'s own colour has its dark row');
    assertContains('value="colour:light:header_colour"', $html, 'and its way back');

    $session = new App\Core\Session();
    $after = dispatch('/admin/appearance', null, 'POST', AppearanceForm::query(['decisions' => Design::load($db), 'look' => [], 'dark' => Design::dark($db)], 'en', '') + ['_csrf' => $session->csrfToken(), 'action' => 'colour:light:text'])->body;
    assertContains('name="dark_color_text_on" value="1" data-by-hand-switch', $after, 'given up on the screen');
    assertTrue(!str_contains($after, 'name="dark_color_text_on" value="1" checked'), 'its switch off');
    assertEquals('#f5e9d0', Design::dark($db)['color_text'], 'and nothing published until Publish');
});

testBothDrivers('a kept design keeps the owner\'s dark colours, and comes back with them', function (string $driver) {
    $db = adminSite($driver);
    $id = DesignLibrary::save($db, 'Night', ['mode' => 'dark'], [], 'minimal', ['color_link' => '#ffcc00']);
    assertEquals('#ffcc00', DesignLibrary::find($db, $id)['dark']['color_link'] ?? null, 'kept');
    $before = DesignLibrary::save($db, 'Older', [], [], 'minimal');
    assertEquals('', DesignLibrary::find($db, $before)['dark']['color_link'] ?? null, 'none kept, none brought back');
});

testBothDrivers('a design exported from the screen carries the owner\'s dark colours in its dark version', function (string $driver) {
    transferSite($driver);
    $fields = appearanceFields(['action' => 'export', 'seed' => '#2b4a6f', 'character' => 'editorial', 'color_link' => '#225588', 'color_link_on' => '1', 'dark_color_text' => '#f5e9d0', 'dark_color_text_on' => '1']);
    $set = App\Modules\Design\DesignSet::parse(adminPost('/admin/appearance', $fields)->body, blockRegistry())['set'] ?? fail('not a design');
    assertEquals('#f5e9d0', $set['dark']['color_text'] ?? null, 'the owner\'s dark colour');
    assertEquals('#225588', $set['dark']['color_link'] ?? null, 'and the colour held for both modes, which stands over a dark version on the site');
    assertEquals('#225588', $set['decisions']['color_link'] ?? null, 'held in light mode too');
});
