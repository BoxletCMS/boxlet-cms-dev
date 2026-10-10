<?php

use App\Core\Session;
use App\Modules\Menus\Menu;

// SEVERAL PAGES, AND SEVERAL PICTURES AND FILES, DELETED AT ONCE (PLAN.md D-218, the owner):
// the ticks in Pages' and Media's lists and Delete above them; and a page deleted, one or
// many, taking its menu items with it. Helpers from pages_admin_test.php and
// media_admin_test.php (adminSite, adminPost, mediaAdminSite, adminUpload).

testBothDrivers('the pages ticked are deleted together, and the rest stay', function (string $driver) {
    $db = adminSite($driver);
    $about = createPage($db, 'en', 'about', 'About', true, [['type' => 'text', 'content' => ['body' => '<p>a</p>']]]);
    $team = createPage($db, 'en', 'team', 'Team', true);
    $kept = createPage($db, 'en', 'contact', 'Contact', true);

    $response = adminPost('/admin/pages/delete', ['ids' => [(string) $about, (string) $team, 'x', '-3', '999999'], 'lang' => 'en']);
    assertRedirectedTo('/admin/pages?lang=en', $response);
    assertEquals([$kept], array_map('intval', array_column($db->all('SELECT id FROM pages ORDER BY id'), 'id')), 'what is left');
    assertEquals(0, (int) ($db->one('SELECT COUNT(*) AS n FROM page_blocks WHERE page_id = ?', [$about])['n'] ?? -1), 'the blocks went with the page');
    assertEquals('2 pages were deleted.', $_SESSION['flash'] ?? null, 'it says how many');
    assertEquals(2, (int) ($db->one("SELECT COUNT(*) AS n FROM activity WHERE action = 'deleted'")['n'] ?? 0), 'each in the activity');

    adminPost('/admin/pages/delete', []);
    assertEquals('No page was selected, so nothing was deleted.', $_SESSION['flash'] ?? null, 'nothing ticked');
});

test('a bulk delete without the form\'s token deletes nothing', function () {
    $db = adminSite('sqlite');
    $id = createPage($db, 'en', 'about', 'About', true);
    $response = dispatch('/admin/pages/delete', null, 'POST', ['ids' => [(string) $id]]);
    assertTrue($response->status !== 302 || ($response->headers['Location'] ?? '') !== '/admin/pages', 'it went through');
    assertTrue($db->one('SELECT id FROM pages WHERE id = ?', [$id]) !== null, 'the page was deleted');
    $media = dispatch('/admin/media/delete', null, 'POST', ['ids' => ['1']]);
    assertTrue($media->status !== 302 || ($media->headers['Location'] ?? '') !== '/admin/media', 'the media one went through');
});

testBothDrivers('a deleted page takes its menu items with it, and the items under one move up', function (string $driver) {
    $db = adminSite($driver);
    $exhibitions = createPage($db, 'en', 'exhibitions', 'Exhibitions', true);
    $past = createPage($db, 'en', 'past', 'Past', true);
    $visit = createPage($db, 'en', 'visit', 'Visit', true);
    $menu = Menu::create($db, 'en', 'Main');
    $item = Menu::addItem($db, $menu, null, $exhibitions, null, null);
    $under = Menu::addItem($db, $menu, $item, $past, null, null);
    $other = Menu::addItem($db, $menu, null, $visit, null, null);
    $footer = Menu::create($db, 'en', 'Footer');
    Menu::addItem($db, $footer, null, $exhibitions, null, null);

    // One page through its row's Delete, as before.
    assertRedirectedTo('/admin/pages', adminPost('/admin/pages/' . $exhibitions . '/delete', []));
    $left = $db->all('SELECT id, parent_id, page_id FROM menu_items ORDER BY id');
    assertEquals([(int) $under, (int) $other], array_map('intval', array_column($left, 'id')), 'its items in both menus are gone');
    assertEquals(null, $left[0]['parent_id'], 'the item under it moved up');

    // And through the ticks.
    adminPost('/admin/pages/delete', ['ids' => [(string) $past, (string) $visit]]);
    assertEquals([], $db->all('SELECT id FROM menu_items'), 'the rest with their pages');
});

testBothDrivers('the pictures ticked are deleted with their files, and one a page shows is kept and named', function (string $driver) {
    $db = mediaAdminSite($driver);
    adminUpload('/admin/media', [
        ['name' => 'spare.jpg', 'tmp_name' => imageFixture(tmpPath('spare.jpg'), 320, 240)],
        ['name' => 'shown.jpg', 'tmp_name' => imageFixture(tmpPath('shown.jpg'), 330, 240)],
    ]);
    $ids = array_map('intval', array_column($db->all('SELECT id FROM media ORDER BY id'), 'id'));
    assertEquals(2, count($ids), 'two uploaded');
    $shown = (int) ($db->one("SELECT id FROM media WHERE filename LIKE 'shown%'")['id'] ?? 0);
    createPage($db, 'en', 'about', 'About us', true, [['type' => 'hero', 'content' => ['heading' => 'Hi', 'image' => $shown]]]);

    $response = adminUpload('/admin/media/delete', [], ['ids' => array_map('strval', $ids), 'show' => 'unused', 'page' => '2']);
    assertRedirectedTo('/admin/media?show=unused&page=2', $response);
    assertEquals([$shown], array_map('intval', array_column($db->all('SELECT id FROM media'), 'id')), 'only the one in use is left');
    $flash = (string) ($_SESSION['flash'] ?? '');
    assertContains('One was deleted.', $flash, 'the count');
    assertContains('About us', $flash, 'the page that keeps the other');
    assertEquals('warning', $_SESSION['flash_kind'] ?? null, 'not coloured as a win');
    $files = glob(tmpPath('admin-media-public') . '/m/*/*') ?: [];
    assertTrue($files !== [] && array_filter($files, static fn (string $f): bool => !str_contains(basename($f), $shown . '-')) === [], 'the deleted picture\'s sizes stayed behind');
});

testBothDrivers('Pages and Media show a tick on every row and Delete above them', function (string $driver) {
    $db = mediaAdminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', true);
    adminUpload('/admin/media', [['name' => 'one.jpg', 'tmp_name' => imageFixture(tmpPath('one.jpg'), 320, 240)]]);
    $media = (int) ($db->one('SELECT id FROM media')['id'] ?? 0);

    $pages = dispatch('/admin/pages')->body;
    assertContains('id="pages-bulk"', $pages, 'the bar');
    assertContains('name="ids[]" value="' . $id . '" form="pages-bulk"', $pages, 'a row\'s tick');
    assertContains('admin-bulk.js', $pages, 'its script');
    $library = dispatch('/admin/media', null, 'GET', [], '203.0.113.10', mediaAdminContainer())->body;
    assertContains('id="media-bulk"', $library, 'the bar');
    assertContains('name="ids[]" value="' . $media . '" form="media-bulk"', $library, 'a row\'s tick');
    // The form posts with the session's token, as every admin form does.
    assertContains('value="' . (new Session())->csrfToken() . '"', $library, 'the token');
});
