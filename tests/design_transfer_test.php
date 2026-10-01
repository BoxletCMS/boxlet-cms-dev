<?php

use App\Core\Settings;
use App\Modules\Appearance\DesignLibrary;
use App\Modules\Design\Characters;
use App\Modules\Design\DesignSet;
use App\Modules\Design\Presets;

// A design as a file on the Appearance screen (PLAN.md D-152): out, in, kept as a character,
// loaded, deleted — every one a plain GET or form post, so all of it works without a script
// (the handoff's test 7). adminSite()/adminPost() from pages_admin_test.php, adminUpload()
// from media_admin_test.php, sampleSet() from design_set_test.php, customFile() from
// characters_test.php.

/** The site's admin, reading imported characters from its database as the bootstrap does. */
function transferSite(string $driver): App\Core\Db
{
    $db = adminSite($driver);
    Characters::use('', static fn () => blockRegistry(), static fn () => $db);

    return $db;
}

/** A design file as an upload. */
function designUpload(string $contents): App\Core\Response
{
    $file = tmpPath('design-upload.json');
    file_put_contents($file, $contents);

    return adminUpload('/admin/appearance/import', [['name' => 'design.json', 'tmp_name' => $file]], [], 'design');
}

/** @return list<string> the activity log's actions for designs, oldest first */
function designActivity(App\Core\Db $db): array
{
    return array_map('strval', array_column($db->all("SELECT action FROM activity WHERE kind = 'design' ORDER BY id"), 'action'));
}

testBothDrivers('a character is exported as its own file, the same text as in designs/core', function (string $driver) {
    $db = transferSite($driver);
    $response = dispatch('/admin/appearance/export/character/soft');
    assertEquals(200, $response->status, 'status');
    assertContains('application/json', $response->headers['Content-Type'] ?? '', 'type');
    assertEquals('attachment; filename="soft.json"', $response->headers['Content-Disposition'] ?? '', 'a file to save');
    assertEquals((string) file_get_contents(dirname(__DIR__) . '/designs/core/soft.json'), $response->body, 'the file');
    assertEquals(['exported'], designActivity($db), 'the log');
    assertEquals(404, dispatch('/admin/appearance/export/character/no-such')->status, 'a character that is not here');
});

// The handoff's test 2, for a kept design with an owner's exception in it (D-153).
testBothDrivers('a kept design is exported with its character\'s composition, and reads back the same', function (string $driver) {
    $db = transferSite($driver);
    $decisions = Presets::get('bold');
    $decisions['color_link'] = '#2a1070';
    $id = DesignLibrary::save($db, 'Autumn Shop', $decisions, ['nav_style' => 'chips'], 'bold');

    $response = dispatch('/admin/appearance/export/library/' . $id);
    assertEquals('attachment; filename="autumn-shop.json"', $response->headers['Content-Disposition'] ?? '', 'named after it');
    $read = DesignSet::parse($response->body, blockRegistry());
    assertEquals([], $read['errors'], 'its own export is refused');
    $set = $read['set'] ?? fail('nothing read');
    assertEquals(DesignLibrary::find($db, $id)['decisions'] ?? [], $set['decisions'], 'the decisions, with the colour by hand');
    assertEquals(Characters::composition('bold'), $set['composition'], 'the composition of the character it was made from');
    // A character sets every choice: the one it made, and the rest as Bold gives them.
    assertEquals('chips', $set['look']['nav_style'], 'its own choice');
    assertEquals(Characters::look('bold')['header_arrangement'], $set['look']['header_arrangement'], 'a choice it left to Bold');
});

testBothDrivers('the design on the screen is exported, published or not', function (string $driver) {
    transferSite($driver);
    $fields = appearanceFields(['action' => 'export', 'seed' => '#2b4a6f', 'character' => 'editorial']);
    $response = adminPost('/admin/appearance', $fields);
    assertEquals('attachment; filename="my-design.json"', $response->headers['Content-Disposition'] ?? '', 'a file');
    $set = DesignSet::parse($response->body, blockRegistry())['set'] ?? fail('not a design');
    assertEquals('#2b4a6f', $set['decisions']['seed'], 'what was on the screen, unpublished');
    assertEquals(Characters::composition('editorial'), $set['composition'], 'the loaded character\'s composition');
});

testBothDrivers('an imported file asks, and Add as character keeps it, under an id of its own', function (string $driver) {
    $db = transferSite($driver);
    $asked = designUpload(customFile('soft'));
    assertEquals(200, $asked->status, 'status');
    assertContains('Import “Soft”?', $asked->body, 'the question');
    assertContains('form="design-import-add"', $asked->body, 'Add as character');
    assertContains('value="import:load"', $asked->body, 'Load into the screen');

    $added = adminPost('/admin/appearance/import/add', []);
    assertRedirectedTo('/admin/appearance', $added);
    assertEquals('imported', Characters::source('soft-2'), 'kept beside Boxlet\'s soft');
    assertEquals('core', Characters::source('soft'), 'Boxlet\'s soft');
    assertContains('“Soft” was added as a character.', (string) ($_SESSION['flash'] ?? ''), 'what the owner is told');
    assertEquals(['imported'], designActivity($db), 'the log');

    // The screen shows it, marked as the owner's, with a way to remove it.
    $screen = dispatch('/admin/appearance');
    assertContains('value="preset:soft-2"', $screen->body, 'its card');
    assertContains('value="character:delete:soft-2"', $screen->body, 'its delete button');
    assertTrue(!str_contains($screen->body, 'value="character:delete:soft"'), 'Boxlet\'s soft offered for deletion');
    assertContains('Custom', $screen->body, 'the Custom label');
    // Asking again with nothing waiting says so, and keeps nothing.
    adminPost('/admin/appearance/import/add', []);
    assertEquals(1, (int) ($db->one('SELECT COUNT(*) AS n FROM design_characters')['n'] ?? 0), 'rows');
});

testBothDrivers('a refused file says why, field by field, and nothing waits', function (string $driver) {
    $db = transferSite($driver);
    $refused = designUpload(customFile('harbour', ['decisions' => ['typography' => 'comic']]));
    assertEquals(422, $refused->status, 'status');
    assertContains('decisions.typography', $refused->body, 'the field and the reason');
    assertEquals(null, $_SESSION['design_import'] ?? null, 'a refused set waits');
    assertContains('This file is not a Boxlet design.', designUpload('{"hello": "world"}')->body, 'not a design');
    assertContains('Choose a design file first.', adminUpload('/admin/appearance/import', [], [], 'design')->body, 'no file');
    assertEquals([], designActivity($db), 'the log');
});

testBothDrivers('Load into the screen fills it with the import, and publishes nothing', function (string $driver) {
    $db = transferSite($driver);
    designUpload(customFile('harbour'));
    $before = $db->all('SELECT * FROM design_tokens ORDER BY group_key');
    $loaded = adminPost('/admin/appearance', appearanceFields(['action' => 'import:load']));
    assertEquals(200, $loaded->status, 'status');
    assertContains('value="#1d3557"', $loaded->body, 'the import\'s seed in the form');
    assertContains('“Harbour” is loaded into the screen.', $loaded->body, 'what the owner is told');
    assertEquals($before, $db->all('SELECT * FROM design_tokens ORDER BY group_key'), 'the published design');
    assertTrue(!Characters::exists('harbour'), 'loading kept it as a character');
});

testBothDrivers('an imported character is deleted from its card; Boxlet\'s are not', function (string $driver) {
    $db = transferSite($driver);
    $slug = Characters::addImported($db, DesignSet::parse(customFile('harbour'), blockRegistry())['set'] ?? fail('not read'));
    $deleted = adminPost('/admin/appearance', appearanceFields(['action' => 'character:delete:' . $slug]));
    assertContains('The character “Harbour” is deleted.', $deleted->body, 'what the owner is told');
    assertTrue(!Characters::exists($slug), 'still there');
    assertEquals(['character_deleted'], designActivity($db), 'the log');
    assertEquals(404, adminPost('/admin/appearance', appearanceFields(['action' => 'character:delete:soft']))->status, 'Boxlet\'s soft');
    assertTrue(Characters::exists('soft'), 'Boxlet\'s soft after the attempt');
});

// The handoff's test 5, and D-156.
testBothDrivers('when the active character is gone, the site renders with the default and the admin notes it once', function (string $driver) {
    $db = transferSite($driver);
    createPage($db, 'en', '', 'Home', true, [['type' => 'hero', 'content' => ['heading' => 'Welcome']]]);
    $slug = Characters::addImported($db, DesignSet::parse(customFile('harbour'), blockRegistry())['set'] ?? fail('not read'));
    Settings::set($db, 'design_character', $slug);
    Characters::deleteImported($db, $slug);

    assertEquals(Presets::DEFAULT, App\Modules\Design\Composition::active($db), 'the character new blocks compose with');
    $_SESSION = [];
    assertEquals(200, dispatch('/')->status, 'a visitor\'s page');
    assertEquals([], designActivity($db), 'a visitor\'s request wrote to the log');

    $_SESSION['admin_id'] = (int) ($db->one('SELECT id FROM admin')['id'] ?? 0);
    $screen = dispatch('/admin/appearance');
    assertEquals(200, $screen->status, 'Appearance opens');
    assertContains('The character “' . $slug . '” this site was composed with is gone.', $screen->body, 'the screen says so');
    dispatch('/admin/appearance');
    assertEquals(['character_missing'], designActivity($db), 'noted once');
});

// The handoff's test 7, in the markup: every way a file goes out or comes in is a plain link
// or a plain form, so the screen does all of it without a script.
testBothDrivers('the screen offers export and import as links and forms, with no script needed', function (string $driver) {
    $db = transferSite($driver);
    Characters::addImported($db, DesignSet::parse(customFile('harbour'), blockRegistry())['set'] ?? fail('not read'));
    $body = dispatch('/admin/appearance')->body;

    assertContains('<form id="design-import" method="post" action="/admin/appearance/import" enctype="multipart/form-data"', $body, 'the import form, outside the design form');
    assertContains('type="file" id="design-file" name="design"', $body, 'the file input');
    assertContains('form="design-import" class="visually-hidden" data-file-sends', $body, 'the input belongs to the import form');
    assertContains('<button type="submit" form="design-import" class="button no-js-only">', $body, 'a button that sends it without a script');
    assertContains('href="/admin/appearance/export/character/soft"', $body, 'a character\'s export is a link');
    assertContains('<button type="submit" form="design-form" name="action" value="export"', $body, 'the screen\'s export is the form\'s own post');
    assertContains('value="character:delete:harbour"', $body, 'an imported character\'s delete is the form\'s own post');
    // And the forms are not nested: #design-import closes before #design-form opens.
    assertTrue(strpos($body, '</form>', (int) strpos($body, 'id="design-import"')) < (int) strpos($body, 'id="design-form"'), 'the import form inside the design form');
});
