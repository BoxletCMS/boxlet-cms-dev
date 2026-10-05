<?php

use App\Modules\Design\Characters;
use App\Modules\Design\DesignSet;
use App\Modules\Design\SetLibrary;

/*
 * BROWSE LIBRARY (PLAN.md D-195, the owner): the sets Boxlet ships beside its characters, in
 * designs/library/, shown in Appearance as cards and each added as a character in one press —
 * what Import → "Add as character" does with a file.
 */

test('every set in the library reads as a character, cleanly, and none under a core id', function () {
    $files = glob(dirname(__DIR__) . '/designs/library/*.json') ?: [];
    assertTrue(count($files) >= 7, 'the library\'s sets');
    $all = SetLibrary::all(blockRegistry());
    assertEquals(count($files), count($all), 'every file read');
    foreach ($files as $file) {
        $id = basename($file, '.json');
        $read = DesignSet::parse((string) file_get_contents($file), blockRegistry());
        assertEquals([], $read['errors'], "{$id}: errors");
        assertEquals([], $read['warnings'], "{$id}: warnings");
        assertEquals($id, $read['set']['id'] ?? null, "{$id}: the file is named by its id");
        assertTrue(($read['set']['composition'] ?? null) !== null, "{$id}: a character");
        assertTrue(!in_array($id, Characters::CORE, true), "{$id}: not a core character too");
    }
    assertEquals(null, SetLibrary::find('../core/minimal', blockRegistry()), 'only a file of the library, by its id');
});

testBothDrivers('Browse library shows each set as a card and adds one as a character in one press', function (string $driver) {
    $db = transferSite($driver);
    assertContains('href="/admin/appearance/browse"', dispatch('/admin/appearance')->body, 'Appearance offers it');

    $page = dispatch('/admin/appearance/browse');
    assertEquals(200, $page->status, 'the library');
    foreach (SetLibrary::all(blockRegistry()) as $id => $set) {
        assertContains(e($set['name']['en']), $page->body, "{$id}: its card");
        assertContains('action="/admin/appearance/browse/' . $id . '"', $page->body, "{$id}: its press");
    }

    $added = adminPost('/admin/appearance/browse/coast', []);
    assertRedirectedTo('/admin/appearance', $added);
    assertEquals('imported', Characters::source('coast'), 'a character now, as an import is');
    assertEquals('library', (string) ($db->one('SELECT source FROM design_characters WHERE slug = ?', ['coast'])['source'] ?? ''), 'kept as from the library');
    assertContains('“Coast” was added as a character.', (string) ($_SESSION['flash'] ?? ''), 'what the owner is told');
    assertEquals(['imported'], designActivity($db), 'the log');
    assertContains('preset:coast', dispatch('/admin/appearance')->body, 'among the tiles');

    $again = dispatch('/admin/appearance/browse')->body;
    assertTrue(!str_contains($again, 'action="/admin/appearance/browse/coast"'), 'not offered twice');
    assertContains(e(t('browse.added')), $again, 'it says so');

    $gone = adminPost('/admin/appearance/browse/nothing-here', []);
    assertRedirectedTo('/admin/appearance/browse', $gone);
    assertEquals(1, (int) ($db->one('SELECT COUNT(*) AS n FROM design_characters')['n'] ?? 0), 'nothing else added');
});
