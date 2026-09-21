<?php

use App\Modules\Appearance\DesignLibrary;
use App\Modules\Design\Design;
use App\Modules\Design\Presets;
use App\Modules\Settings\ChromeLook;

// The designs the owner keeps (PLAN.md D-061): saved, brought back, written over, deleted —
// and none of it touching the site until Publish. adminSite() and adminPost() come from
// pages_admin_test.php, appearanceFields() from fixtures.php.

testBothDrivers('a design is kept whole, and comes back as decisions rather than colours', function (string $driver) {
    $db = adminSite($driver);

    $id = DesignLibrary::save($db, ' Autumn  light ', Presets::get('soft'), ['header_surface' => 'tinted'] + ChromeLook::stored($db), 'soft');
    $saved = DesignLibrary::find($db, $id);

    assertEquals('Autumn light', $saved['name'] ?? '', 'the name, tidied');
    assertEquals(Presets::get('soft')['seed'], $saved['decisions']['seed'] ?? '', 'the seed it was kept with');
    assertEquals('tinted', $saved['look']['header_surface'] ?? '', 'the header surface');
    assertEquals('', $saved['look']['density'] ?? 'missing', 'a choice left to the character stays left to it');
    assertEquals('soft', $saved['character'] ?? '', 'where it came from');

    // Decisions, never derived values: no colour the palette works out is in the row.
    $row = $db->one('SELECT decisions_json FROM design_library WHERE id = ?', [$id]);
    $stored = json_decode((string) ($row['decisions_json'] ?? '{}'), true);
    assertEquals(null, $stored['accent'] ?? null, 'a derived colour was stored');
    assertTrue(isset($stored['seed'], $stored['typography'], $stored['container']), 'the decisions are all there');
});

testBothDrivers('saving under a name that exists writes over it rather than making a second', function (string $driver) {
    $db = adminSite($driver);

    $first = DesignLibrary::save($db, 'Autumn', Presets::get('soft'), [], 'soft');
    $again = DesignLibrary::save($db, 'Autumn', Presets::get('bold'), [], 'bold');

    assertEquals($first, $again, 'the same row');
    assertEquals(1, count(DesignLibrary::all($db)), 'designs kept');
    assertEquals(Presets::get('bold')['seed'], DesignLibrary::find($db, $first)['decisions']['seed'] ?? '', 'the newer values');
});

test('a row damaged by hand comes back as something the design layer accepts', function () {
    $db = adminSite('sqlite');
    $id = DesignLibrary::save($db, 'Broken', Presets::get('minimal'), []);
    $db->query('UPDATE design_library SET decisions_json = ?, look_json = ? WHERE id = ?', ['{"seed":"not a colour","scale":"9"}', 'null', $id]);

    $saved = DesignLibrary::find($db, $id);

    assertEquals(Presets::get(Presets::DEFAULT)['seed'], $saved['decisions']['seed'] ?? '', 'the seed falls back');
    assertTrue(in_array($saved['decisions']['scale'] ?? '', App\Modules\Design\Tokens::SCALES, true), 'so does the scale');
    assertEquals(7, count($saved['look'] ?? []), 'every chrome choice is there, empty');
});

testBothDrivers('the screen keeps what is on it, and the site does not move', function (string $driver) {
    $db = adminSite($driver);
    $published = Design::load($db)['seed'];

    $response = adminPost('/admin/appearance', appearanceFields([
        'seed' => '#1f1fd1',
        'look_header_surface' => 'contrast',
        'library_name' => 'Deep blue',
        'action' => 'library:save',
    ]));

    assertEquals(200, $response->status, 'the screen comes back, not a redirect');
    assertContains('Deep blue', $response->body, 'it says what was kept');
    $saved = DesignLibrary::all($db);
    assertEquals(1, count($saved), 'one design kept');
    assertEquals('#1f1fd1', $saved[0]['decisions']['seed'], 'with the colour that was on the screen');
    assertEquals('contrast', $saved[0]['look']['header_surface'], 'and the header surface');
    assertEquals($published, Design::load($db)['seed'], 'the published design has not moved');

    // And the screen still shows the work it was asked to keep, rather than the site's.
    assertContains('value="#1f1fd1"', $response->body, 'the colour is still on the screen');
});

test('a design with no name is refused, and says so beside the field', function () {
    $db = adminSite('sqlite');

    $response = adminPost('/admin/appearance', appearanceFields([
        'library_name' => '   ',
        'action' => 'library:save',
    ]));

    assertEquals(422, $response->status, 'refused');
    assertContains(t('appearance.library.name_needed'), $response->body, 'the message');
    assertEquals(0, count(DesignLibrary::all($db)), 'nothing was kept');
});

testBothDrivers('using a kept design fills the screen with it and publishes nothing', function (string $driver) {
    $db = adminSite($driver);
    $published = Design::load($db)['seed'];
    $id = DesignLibrary::save($db, 'Autumn', ['seed' => '#7a2e2e'] + Presets::get('editorial'), ['header_layout' => 'sticky'], 'editorial');

    $response = adminPost('/admin/appearance', appearanceFields(['action' => 'library:use:' . $id]));

    assertEquals(200, $response->status, 'the screen');
    assertContains('value="#7a2e2e"', $response->body, 'the kept colour is on the screen');
    assertContains('value="sticky" checked', $response->body, 'and the kept header arrangement');
    assertContains('Autumn', $response->body, 'it says which design');
    assertEquals($published, Design::load($db)['seed'], 'the site has not changed');
});

testBothDrivers('a kept design is deleted by itself, and takes nothing else with it', function (string $driver) {
    $db = adminSite($driver);
    $keep = DesignLibrary::save($db, 'Keep me', Presets::get('soft'), []);
    $go = DesignLibrary::save($db, 'Throw me', Presets::get('bold'), []);

    $response = adminPost('/admin/appearance', appearanceFields(['action' => 'library:delete:' . $go]));

    assertEquals(200, $response->status, 'the screen');
    assertEquals(null, DesignLibrary::find($db, $go), 'the one deleted');
    assertTrue(DesignLibrary::find($db, $keep) !== null, 'the one that stays');
});

test('a design that is not there any more is not an error page', function () {
    adminSite('sqlite');

    assertEquals(404, adminPost('/admin/appearance', appearanceFields(['action' => 'library:use:4242']))->status, 'using one');
    assertEquals(404, adminPost('/admin/appearance', appearanceFields(['action' => 'library:delete:4242']))->status, 'deleting one');
});

test('the library is in the rail, with a way to keep what is on the screen', function () {
    $db = adminSite('sqlite');
    assertContains(t('appearance.library.empty_rail'), dispatch('/admin/appearance')->body, 'an empty library says so');

    DesignLibrary::save($db, 'Autumn', Presets::get('soft'), [], 'soft');
    $body = dispatch('/admin/appearance')->body;

    assertContains('>Autumn</span>', $body, 'the design is listed');
    assertContains('value="library:use:', $body, 'a way to use it');
    assertContains('value="library:delete:', $body, 'a way to delete it');
    // Overwriting is on the card itself: the name is the design's own, so it cannot be
    // mistyped into a second design nobody meant to make (D-064).
    assertContains('value="library:save:', $body, 'a way to write over it from its own card');
    assertContains('name="library_name"', $body, 'a name for a new one');
    assertContains('value="library:save"', $body, 'and a way to keep it');
});

testBothDrivers('writing over a design from its own card needs no name', function (string $driver) {
    $db = adminSite($driver);
    $id = DesignLibrary::save($db, 'Autumn', Presets::get('soft'), [], 'soft');

    $response = adminPost('/admin/appearance', appearanceFields(['seed' => '#1f1fd1', 'action' => 'library:save:' . $id]));

    assertEquals(200, $response->status, 'the screen comes back');
    assertEquals(1, count(DesignLibrary::all($db)), 'still one design');
    $again = DesignLibrary::find($db, $id) ?? fail('the design it was written into is gone');
    assertEquals('#1f1fd1', $again['decisions']['seed'], 'holding what was on the screen');
    assertEquals('Autumn', $again['name'], 'under the name it already had');
    assertEquals(404, adminPost('/admin/appearance', appearanceFields(['action' => 'library:save:4242']))->status, 'one that is gone');
});
