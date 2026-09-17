<?php

use App\Modules\Menus\Menu;
use App\Modules\Menus\MenuTree;

// Menus through the admin, as a person builds one (PLAN.md D-028, resolving O-7).
// adminSite() and adminPost() come from pages_admin_test.php; run.php requires every test
// file before running any test, so they are defined by the time these run.

testBothDrivers('a menu is created, and its name is free again in another language', function (string $driver) {
    $db = adminSite($driver);

    $response = adminPost('/admin/menus', ['name' => 'Header', 'locale' => 'en']);
    assertEquals(302, $response->status, 'creating a menu');
    assertEquals(1, count(Menu::all($db)), 'menus after creating one');

    // The same name in another language is the normal case: a menu belongs to one locale
    // so a translation has its own labels.
    assertEquals(302, adminPost('/admin/menus', ['name' => 'Header', 'locale' => 'hr'])->status, 'the same name in hr');
    assertEquals(2, count(Menu::all($db)), 'menus after the second');

    // The same name in the same language is refused in words, not by a constraint
    // violation reaching the screen.
    $again = adminPost('/admin/menus', ['name' => 'Header', 'locale' => 'en']);
    assertEquals(422, $again->status, 'a duplicate name');
    assertContains(e(t('menus.name_taken')), $again->body, 'it does not say the name is taken');
    assertEquals(2, count(Menu::all($db)), 'a third menu was created anyway');
});

testBothDrivers('an item points at a page or carries its own address, and a refused address is stored as nothing', function (string $driver) {
    $db = adminSite($driver);
    $page = createPage($db, 'en', 'about', 'About');
    $menu = Menu::create($db, 'en', 'Header');

    assertRedirectedTo('/admin/menus/' . $menu, adminPost("/admin/menus/{$menu}/items", ['page_id' => (string) $page, 'url' => '', 'label' => '']));
    assertRedirectedTo('/admin/menus/' . $menu, adminPost("/admin/menus/{$menu}/items", ['page_id' => '', 'url' => '/contact', 'label' => 'Contact']));

    // javascript: is refused by SafeUrl, the same class the block editor's link fields use.
    // The item is still created — it simply points nowhere, which the admin shows and the
    // site leaves out. Saving nothing at all would lose what the owner typed the label for.
    adminPost("/admin/menus/{$menu}/items", ['page_id' => '', 'url' => 'javascript:alert(1)', 'label' => 'Evil']);

    $items = MenuTree::admin($db, $menu);
    assertEquals(3, count($items), 'items in the menu');
    assertEquals('About', $items[0]['label'], 'a label falls back to the page title');
    assertEquals('/contact', $items[1]['target'], 'the address item');
    assertTrue($items[2]['broken'], 'the refused address was stored as a working link');

    // A real guard, not assertTrue() and not ??: the first does not narrow the type for
    // static analysis, and the second cannot tell a stored null from a missing key — which
    // is the whole assertion here.
    $stored = Menu::findItem($db, $items[2]['id']);
    if ($stored === null) {
        fail('the item that was just created is not there');
    }
    assertEquals(null, $stored['url'], 'the refused address was kept');
});

testBothDrivers('a menu is one level deep', function (string $driver) {
    $db = adminSite($driver);
    $menu = Menu::create($db, 'en', 'Header');
    $top = Menu::addItem($db, $menu, null, null, '/one', 'One');
    $child = Menu::addItem($db, $menu, $top, null, '/two', 'Two');

    if ($top === null || $child === null) {
        fail('the first two items were refused');
    }
    // SQL cannot say "no grandchildren" portably, so the model says it.
    assertEquals(null, Menu::addItem($db, $menu, $child, null, '/three', 'Three'), 'a third level was accepted');

    $items = MenuTree::admin($db, $menu);
    assertEquals([0, 1], array_column($items, 'depth'), 'depths');
});

testBothDrivers('both ordering paths work: a whole order, and one item moved', function (string $driver) {
    $db = adminSite($driver);
    $menu = Menu::create($db, 'en', 'Header');
    $a = (int) Menu::addItem($db, $menu, null, null, '/a', 'A');
    $b = (int) Menu::addItem($db, $menu, null, null, '/b', 'B');
    $c = (int) Menu::addItem($db, $menu, null, null, '/c', 'C');

    $labels = static fn (): array => array_column(MenuTree::admin($db, $menu), 'label');
    assertEquals(['A', 'B', 'C'], $labels(), 'the order they were added in');

    // The drag: the whole sibling group, as the hidden field carries it.
    adminPost("/admin/menus/{$menu}/order", ['order' => implode(',', [$c, $a, $b])]);
    assertEquals(['C', 'A', 'B'], $labels(), 'after a posted order');

    // The button: one item, one direction. The path a keyboard uses.
    adminPost("/admin/menus/{$menu}/order", ['order' => '', 'item' => (string) $a, 'move' => 'up']);
    assertEquals(['A', 'C', 'B'], $labels(), 'after moving one item up');

    // A posted order that is not exactly this sibling group changes nothing, rather than
    // renumbering the group into a shape nobody asked for.
    adminPost("/admin/menus/{$menu}/order", ['order' => implode(',', [$a, $c])]);
    assertEquals(['A', 'C', 'B'], $labels(), 'a short order was applied');
});

testBothDrivers('an item whose page is a draft or deleted leaves the site but stays in the admin', function (string $driver) {
    $db = adminSite($driver);
    $live = createPage($db, 'en', 'about', 'About', true);
    $draft = createPage($db, 'en', 'team', 'Team', false);
    $doomed = createPage($db, 'en', 'old', 'Old', true);

    $menu = Menu::create($db, 'en', 'Header');
    Menu::addItem($db, $menu, null, $live, null, null);
    Menu::addItem($db, $menu, null, $draft, null, 'Our team');
    Menu::addItem($db, $menu, null, $doomed, null, 'Old page');

    // Deleting the page empties the reference (0015 is SET NULL) and leaves the item.
    $db->query('DELETE FROM pages WHERE id = ?', [$doomed]);

    $admin = MenuTree::admin($db, $menu);
    assertEquals(3, count($admin), 'the admin hides an item the owner has to fix');
    assertEquals([false, true, true], array_column($admin, 'hidden'), 'which items visitors miss');
    assertTrue($admin[2]['broken'], 'the item whose page was deleted is not marked broken');
    // A draft page is a state, not damage: the item comes back when the page is published.
    assertTrue(!$admin[1]['broken'], 'a draft page was treated as damage');

    $visitors = MenuTree::forVisitors($db, 'en', 'Header');
    assertEquals(['About'], array_column($visitors, 'label'), 'what a visitor is offered');
});

testBothDrivers('a parent that visitors cannot see takes its children with it', function (string $driver) {
    $db = adminSite($driver);
    $draft = createPage($db, 'en', 'team', 'Team', false);
    $menu = Menu::create($db, 'en', 'Header');
    $parent = (int) Menu::addItem($db, $menu, null, $draft, null, 'Our team');
    Menu::addItem($db, $menu, $parent, null, '/contact', 'Contact');

    // A submenu hanging under nothing is not a menu.
    assertEquals([], MenuTree::forVisitors($db, 'en', 'Header'), 'a child survived its hidden parent');
});

test('every menu write needs a CSRF token', function () {
    $db = adminSite('sqlite');
    $menu = Menu::create($db, 'en', 'Header');

    foreach ([
        '/admin/menus',
        "/admin/menus/{$menu}/rename",
        "/admin/menus/{$menu}/delete",
        "/admin/menus/{$menu}/items",
        "/admin/menus/{$menu}/order",
    ] as $path) {
        // The router refuses a state-changing request without a token before it reaches
        // the controller, so this asserts the routes are guarded at all.
        assertEquals(403, dispatch($path, null, 'POST', ['name' => 'X'])->status, "unguarded: {$path}");
    }
});
