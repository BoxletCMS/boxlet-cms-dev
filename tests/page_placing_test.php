<?php

use App\Modules\Pages\PagePlacing;

/*
 * A PAGE PLACED UNDER ANOTHER IN THE PAGE LIST, AND ITS TRANSLATIONS FOLLOWING (PLAN.md
 * D-133). assertMovedTo() is in redirects_test.php, placePage() in nested_addresses_test.php.
 */

/** Where a page stands: its parent's slug ('-' for none) and its place among its siblings. */
function standing(App\Core\Db $db, int $id): string
{
    $at = PagePlacing::whereIs($db, $id) ?? fail('no page ' . $id);
    $parent = $at['parent'] === null ? '-' : (string) ($db->one('SELECT slug FROM pages WHERE id = ?', [$at['parent']])['slug'] ?? '?');

    return $parent . '#' . $at['position'];
}

/**
 * @param array<string, string> $fields
 */
function placeVia(int $id, array $fields): void
{
    assertRedirectedTo('/admin/pages', adminPost("/admin/pages/{$id}/place", $fields));
}

testBothDrivers('→ puts a page under the one above it, ← takes it out after its old parent, and Undo puts it back', function (string $driver) {
    $db = adminSite($driver);
    $services = createPage($db, 'en', 'services', 'Services');
    $web = createPage($db, 'en', 'web-design', 'Web design');
    $about = createPage($db, 'en', 'about', 'About');

    placeVia($web, ['to' => 'in']);
    assertEquals('services#0', standing($db, $web), 'under the page above');
    assertEquals(t('pages.place.done_under', ['title' => 'Web design', 'parent' => 'Services']), $_SESSION['flash'] ?? null, 'what the owner is told');
    assertEquals(200, dispatch('/services/web-design')->status, 'its new address');
    assertMovedTo('/services/web-design', dispatch('/web-design'), 'its old one');

    // The list offers Undo once, naming where it was.
    $list = dispatch('/admin/pages')->body;
    assertContains('name="undo" value="1"', $list, 'Undo offered');
    assertTrue(!str_contains(dispatch('/admin/pages')->body, 'name="undo" value="1"'), 'Undo offered twice');

    placeVia($web, ['to' => 'out']);
    assertEquals('-#1', standing($db, $web), 'out, just after its old parent');
    assertEquals('-#2', standing($db, $about), 'the page below moved down one');

    // Undo is the same request naming the old place; and a move made on one language's
    // list goes back to that list.
    assertRedirectedTo('/admin/pages?lang=en', adminPost("/admin/pages/{$web}/place", ['parent' => (string) $services, 'position' => '0', 'undo' => '1', 'lang' => 'en']));
    assertEquals('services#0', standing($db, $web), 'back where it was');
    assertEquals(1, (int) ($db->one("SELECT COUNT(*) AS n FROM activity WHERE kind = 'page' AND action = 'placed'")['n'] ?? 0) > 0 ? 1 : 0, 'in the log');
});

testBothDrivers('the rules hold on the server, whatever the list offered', function (string $driver) {
    $db = adminSite($driver);
    $home = createPage($db, 'en', '', 'Home');
    $a = createPage($db, 'en', 'a', 'A');
    $b = createPage($db, 'en', 'b', 'B');
    $c = createPage($db, 'en', 'c', 'C');
    $d = createPage($db, 'en', 'd', 'D');
    $hr = createPage($db, 'hr', 'hr-page', 'HR');

    $refused = function (int $id, array $fields, string $key, array $with = []) use ($db): void {
        $before = standing($db, $id);
        placeVia($id, $fields);
        assertEquals(t($key, $with), $_SESSION['flash'] ?? null, $key);
        assertEquals($before, standing($db, $id), $key . ': it moved anyway');
    };

    $refused($home, ['to' => 'in'], 'pages.place.nothing_above');
    $refused($a, ['to' => 'in'], 'pages.place.home');
    $refused($a, ['parent' => (string) $hr], 'pages.place.other_language');
    $refused($a, ['to' => 'out'], 'pages.place.top_already');

    // Three levels: a > b > c is the deepest, so d cannot go under c, and a cannot go
    // under d taking two levels with it.
    PagePlacing::place($db, $b, $a, null);
    PagePlacing::place($db, $c, $b, null);
    $refused($d, ['parent' => (string) $c], 'pages.place.too_deep', ['levels' => '3']);
    $refused($a, ['parent' => (string) $d], 'pages.place.too_deep', ['levels' => '3']);
    $refused($a, ['parent' => (string) $c], 'pages.place.own_child');
    $refused($a, ['parent' => (string) $a], 'pages.place.own_child');
    assertEquals(200, dispatch('/a/b/c')->status, 'the three levels themselves');
});

testBothDrivers('translations follow where the parent is translated, and stay where it is not', function (string $driver) {
    $db = installedSite(['en' => 'English', 'hr' => 'Hrvatski', 'de' => 'Deutsch'], $driver);
    createAdmin($db, 'owner@example.com', 'correct horse battery staple');
    $_SESSION['admin_id'] = (int) ($db->one('SELECT id FROM admin')['id'] ?? 0);
    $registry = blockRegistry();
    $services = createPage($db, 'en', 'services', 'Services');
    $web = createPage($db, 'en', 'web-design', 'Web design');
    $hrServices = (int) App\Modules\Pages\Translations::create($db, $registry, $services, 'hr');
    $hrWeb = (int) App\Modules\Pages\Translations::create($db, $registry, $web, 'hr');
    $deWeb = (int) App\Modules\Pages\Translations::create($db, $registry, $web, 'de');

    PagePlacing::place($db, $web, $services, null);
    assertEquals($hrServices, (int) ($db->one('SELECT parent_id FROM pages WHERE id = ?', [$hrWeb])['parent_id'] ?? 0), 'Croatian, where Services is translated, followed');
    assertEquals(null, $db->one('SELECT parent_id FROM pages WHERE id = ?', [$deWeb])['parent_id'] ?? null, 'German, where it is not, moved');

    // Back out: to the top level, which every language has.
    PagePlacing::place($db, $web, null, null);
    assertEquals(null, $db->one('SELECT parent_id FROM pages WHERE id = ?', [$hrWeb])['parent_id'] ?? null, 'Croatian followed out');
});

testBothDrivers('a parent translated after its children adopts them, and translations keep the source\'s order', function (string $driver) {
    $db = adminSite($driver);
    $registry = blockRegistry();
    $services = createPage($db, 'en', 'services', 'Services');
    $one = createPage($db, 'en', 'first', 'First');
    $two = createPage($db, 'en', 'second', 'Second');
    PagePlacing::place($db, $one, $services, null);
    PagePlacing::place($db, $two, $services, null);

    // The children first, in the opposite order, then the parent.
    $hrTwo = (int) App\Modules\Pages\Translations::create($db, $registry, $two, 'hr');
    $hrOne = (int) App\Modules\Pages\Translations::create($db, $registry, $one, 'hr');
    assertEquals(null, $db->one('SELECT parent_id FROM pages WHERE id = ?', [$hrOne])['parent_id'] ?? null, 'a child translated before its parent has one to go under');
    $hrServices = (int) App\Modules\Pages\Translations::create($db, $registry, $services, 'hr');

    foreach ([$hrOne, $hrTwo] as $child) {
        assertEquals($hrServices, (int) ($db->one('SELECT parent_id FROM pages WHERE id = ?', [$child])['parent_id'] ?? 0), 'adopted when the parent was translated');
    }
    assertEquals(['first', 'second'], array_column($db->all('SELECT slug FROM pages WHERE parent_id = ? ORDER BY sort', [$hrServices]), 'slug'), 'in the source\'s order, not the order translated');
});
