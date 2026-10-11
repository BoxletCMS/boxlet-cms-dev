<?php

// NOTHING STANDS UNDER A HOME PAGE (PLAN.md O-55, the owner, 2026-10-11): the page list's move
// refused it, the editor and Publish offered it; now none does, and migration 0042 moves a
// page that stood there to the top level, its address unchanged. Helpers from
// pages_admin_test.php and builder_test.php.

use App\Modules\Pages\PageTree;

testBothDrivers('migration 0042: a page under a home page moves to the top level, its address unchanged', function (string $driver) {
    $db = adminSite($driver);
    $home = createPage($db, 'en', '', 'Home', true);
    $about = createPage($db, 'en', 'about', 'About', true);
    $team = createPage($db, 'en', 'team', 'Team', true);
    $db->query('UPDATE pages SET parent_id = ? WHERE id = ?', [$home, $about]);
    $db->query('UPDATE pages SET parent_id = ? WHERE id = ?', [$about, $team]);
    $sql = (string) file_get_contents(dirname(__DIR__) . '/migrations/0042_nothing_under_home.sql');
    foreach (array_filter(array_map('trim', explode(';', (string) preg_replace('~^--.*$~m', '', $sql)))) as $statement) {
        $db->query($statement);
    }
    $row = $db->one('SELECT parent_id FROM pages WHERE id = ?', [$about]);
    assertTrue(is_array($row) && array_key_exists('parent_id', $row) && $row['parent_id'] === null, 'About at the top level');
    assertEquals($about, (int) ($db->one('SELECT parent_id FROM pages WHERE id = ?', [$team])['parent_id'] ?? 0), 'Team still under About');
    assertEquals(200, dispatch('/about')->status, 'About\'s address');
    assertEquals(200, dispatch('/about/team')->status, 'Team\'s, under it');
});

test('the editor and Publish offer no home page as a parent, and refuse one however it arrives', function () {
    $db = adminSite('sqlite');
    $home = createPage($db, 'en', '', 'Home', true);
    $services = createPage($db, 'en', 'services', 'Services', true);
    $about = createPage($db, 'en', 'about', 'About', true, [['type' => 'text', 'content' => ['body' => '<p>x</p>']]]);
    assertEquals([$services], array_column(PageTree::parents($db, 'en', $about), 'id'), 'what may be its parent');
    assertTrue(in_array($home, array_column(PageTree::parentOptions($db, 'en', null), 'id'), true), 'a link may still lead home');

    // Through the plain form...
    $blockId = (string) ($db->one('SELECT id FROM page_blocks WHERE page_id = ?', [$about])['id'] ?? '');
    $response = adminPost("/admin/pages/{$about}", [
        'title' => 'About', 'slug' => 'about', 'parent_id' => (string) $home,
        'blocks' => [['id' => $blockId, 'type' => 'text', 'heading' => '', 'body' => '<p>x</p>']],
        'action' => 'publish', '_end' => '1',
    ]);
    assertEquals(422, $response->status, 'the plain form');
    // ...and through the builder's draft and Publish.
    [$document] = builderBand($about);
    $document['parent_id'] = $home;
    draftRequest($about, ['version' => 0, 'document' => $document]);
    assertEquals(422, builderRequest("/admin/pages/{$about}/publish", [])->status, 'Publish');
    $row = $db->one('SELECT parent_id FROM pages WHERE id = ?', [$about]);
    assertTrue(is_array($row) && array_key_exists('parent_id', $row) && $row['parent_id'] === null, 'stored parent');
});
