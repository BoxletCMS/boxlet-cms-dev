<?php

use App\Core\Db;
use App\Modules\Pages\Page;
use App\Modules\Pages\PageTree;

// Page order (PLAN.md D-011). Pages are ordered by `sort` within the same parent and
// locale; a page's parent is changed in page settings, never by dragging.
//
// The column has been in the schema since Slice 3 with nothing writing it, so every page
// in an existing install sits at 0. That is why the order is `sort, title, id` rather than
// `sort` alone, and why creating a page has to claim a position instead of taking the
// default.

/**
 * @param array<string, string|null> $tree child title => parent title
 * @return array<string, int> title => id
 */
function orderedPages(Db $db, array $tree, string $locale = 'en'): array
{
    $ids = [];
    foreach (array_keys($tree) as $title) {
        $ids[$title] = createPage($db, $locale, strtolower((string) $title), (string) $title);
    }
    foreach ($tree as $title => $parent) {
        if ($parent !== null) {
            Page::update($db, $ids[$title], [
                'title' => (string) $title,
                'slug' => strtolower((string) $title),
                'parent_id' => $ids[$parent],
                'status' => 'draft',
            ], []);
        }
    }

    return $ids;
}

/**
 * @return list<string> the listing as "--Title", one dash per level
 */
function listingShape(Db $db): array
{
    return array_map(
        static fn (array $row): string => str_repeat('-', $row['depth']) . $row['title'],
        PageTree::listing($db),
    );
}

testBothDrivers('a new page takes the next position instead of tying at zero', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $ids = orderedPages($db, ['First' => null, 'Second' => null, 'Third' => null]);

    $sorts = [];
    foreach ($ids as $title => $id) {
        $row = $db->one('SELECT sort FROM pages WHERE id = ?', [$id]);
        $sorts[$title] = (int) ($row['sort'] ?? -1);
    }

    assertEquals([0, 1, 2], array_values($sorts), 'the positions pages were created at');
    assertEquals(['First', 'Second', 'Third'], listingShape($db), 'creation order is the listing order');
});

testBothDrivers('the listing is the tree, deepest last, with a depth per row', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    orderedPages($db, ['About' => null, 'Team' => 'About', 'Ada' => 'Team', 'Services' => null]);

    assertEquals(['About', '-Team', '--Ada', 'Services'], listingShape($db), 'the tree');
});

testBothDrivers('the ends of a sibling group are marked, so Up and Down can be disabled', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    orderedPages($db, ['One' => null, 'Two' => null, 'Three' => null]);

    $ends = [];
    foreach (PageTree::listing($db) as $row) {
        $ends[$row['title']] = ($row['first'] ? 'first' : '') . ($row['last'] ? 'last' : '');
    }

    assertEquals(['One' => 'first', 'Two' => '', 'Three' => 'last'], $ends, 'which rows are at an end');
});

testBothDrivers('moving a page up swaps it with the sibling above', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $ids = orderedPages($db, ['One' => null, 'Two' => null, 'Three' => null]);

    assertTrue(PageTree::move($db, $ids['Three'], 'up'), 'the move was refused');
    assertEquals(['One', 'Three', 'Two'], listingShape($db), 'after moving Three up');

    assertTrue(PageTree::move($db, $ids['Three'], 'down'), 'the move back was refused');
    assertEquals(['One', 'Two', 'Three'], listingShape($db), 'after moving it back');
});

testBothDrivers('a page at the end of its group will not move past it', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $ids = orderedPages($db, ['One' => null, 'Two' => null]);

    assertTrue(!PageTree::move($db, $ids['One'], 'up'), 'the first page moved up');
    assertTrue(!PageTree::move($db, $ids['Two'], 'down'), 'the last page moved down');
    assertEquals(['One', 'Two'], listingShape($db), 'nothing moved');
});

testBothDrivers('a child moves among its own siblings, not among its parents', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $ids = orderedPages($db, ['About' => null, 'Team' => 'About', 'Ada' => 'About', 'Services' => null]);

    assertEquals(['About', '-Team', '-Ada', 'Services'], listingShape($db), 'before');
    assertTrue(PageTree::move($db, $ids['Ada'], 'up'), 'the child would not move');
    assertEquals(['About', '-Ada', '-Team', 'Services'], listingShape($db), 'the child moved within its parent');
});

// The order arrives from a form, so it is a claim about the tree rather than a fact.
// Writing positions for pages that are not siblings would re-file them, which is the one
// thing a drag must never do.
testBothDrivers('an order naming pages from two parents is refused whole', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $ids = orderedPages($db, ['About' => null, 'Team' => 'About', 'Services' => null]);
    $before = listingShape($db);

    assertTrue(!PageTree::reorder($db, [$ids['About'], $ids['Team']]), 'a mixed order was accepted');
    assertTrue(!PageTree::reorder($db, [$ids['About']]), 'a partial group was accepted');
    assertTrue(!PageTree::reorder($db, []), 'an empty order was accepted');
    assertEquals($before, listingShape($db), 'the tree changed after a refused order');
});

testBothDrivers('a page given a new parent goes last among its new siblings', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $ids = orderedPages($db, ['About' => null, 'Team' => 'About', 'Ada' => 'About', 'Moved' => null]);

    Page::update($db, $ids['Moved'], [
        'title' => 'Moved', 'slug' => 'moved', 'parent_id' => $ids['About'], 'status' => 'draft',
    ], []);

    // It kept sort 1 from the top level, where Team already sits. Without appending it
    // would tie with Team and land wherever the title tie-break put it.
    assertEquals(['About', '-Team', '-Ada', '-Moved'], listingShape($db), 'the reparented page went last');
});

// Through the admin. The router checks the CSRF token on every POST before the action
// runs, so there is no second check in the controller to test — what is tested here is
// that both paths reach the same endpoint and that a refused order is reported rather
// than swallowed.

testBothDrivers('the page list renders the tree with its order controls', function (string $driver) {
    $db = adminSite($driver);
    $ids = orderedPages($db, ['About' => null, 'Team' => 'About']);

    $body = dispatch('/admin/pages')->body;

    assertContains('class="page-name depth-0"', $body, 'a top-level row');
    assertContains('class="page-name depth-1"', $body, 'an indented child');
    // The group a drag may not leave is the parent's, not the depth's: cousins share a
    // depth and must not share a group.
    assertContains('data-page-group="en:0"', $body, 'a top-level row is grouped at the root');
    assertContains('data-page-group="en:' . $ids['About'] . '"', $body, 'a child is grouped under its parent');
    assertContains('id="page-move-' . $ids['Team'] . '"', $body, 'the form the buttons submit');
    // About is alone at the top level and Team is an only child, so both rows are at both
    // ends of their group: four disabled buttons, and nothing that moves nowhere.
    assertEquals(4, substr_count($body, 'move-button" disabled'), 'disabled Up and Down on both rows');
});

testBothDrivers('a drag posts the new sibling order and it sticks', function (string $driver) {
    $db = adminSite($driver);
    $ids = orderedPages($db, ['One' => null, 'Two' => null, 'Three' => null]);

    $order = implode(',', [$ids['Three'], $ids['One'], $ids['Two']]);
    assertRedirectedTo('/admin/pages', adminPost('/admin/pages/order', ['order' => $order]));
    assertEquals(['Three', 'One', 'Two'], listingShape($db), 'after the drag');
});

testBothDrivers('the Up button names one page and moves it', function (string $driver) {
    $db = adminSite($driver);
    $ids = orderedPages($db, ['One' => null, 'Two' => null]);

    assertRedirectedTo('/admin/pages', adminPost('/admin/pages/order', ['id' => (string) $ids['Two'], 'move' => 'up']));
    assertEquals(['Two', 'One'], listingShape($db), 'after Up');
});

testBothDrivers('an order that is not one sibling group is refused and reported', function (string $driver) {
    $db = adminSite($driver);
    $ids = orderedPages($db, ['About' => null, 'Team' => 'About']);
    $before = listingShape($db);

    $order = implode(',', [$ids['About'], $ids['Team']]);
    assertRedirectedTo('/admin/pages', adminPost('/admin/pages/order', ['order' => $order]));
    assertEquals($before, listingShape($db), 'a page was re-filed by a reorder');
    assertContains(t('pages.reorder_failed'), dispatch('/admin/pages')->body, 'the refusal was swallowed');
});

// The property that matters is not which status a visitor gets, it is that the site did
// not move. Asserting the redirect shape would pin the middleware; asserting the tree
// pins the security.
testBothDrivers('a visitor cannot reorder the site', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $ids = orderedPages($db, ['One' => null, 'Two' => null]);
    unset($_SESSION['admin_id']);

    $response = adminPost('/admin/pages/order', ['id' => (string) $ids['Two'], 'move' => 'up']);

    assertTrue($response->status !== 200, 'the reorder route answered a visitor with a page');
    assertEquals(['One', 'Two'], listingShape($db), 'a visitor reordered the site');
});

// POST /admin/pages/{id} is the save route for BOTH editors. The builder renders a
// parent_id select and submits one; the plain editor rendered no parent control at all,
// so it submitted none, and the controller read that as "no parent" and wrote null. A
// child page saved from the fallback editor was silently promoted to the top level —
// and with ordering it also lost its place, since a reparented page is appended.
//
// The fallback editor exists to fix a page when the visual one will not load. Dropping
// the hierarchy is the opposite of that, so it is fixed here rather than built upon
// (CLAUDE.md rule 8). Not part of D-011.
testBothDrivers('saving from the plain editor keeps the page under its parent', function (string $driver) {
    $db = adminSite($driver);
    $ids = orderedPages($db, ['About' => null, 'Team' => 'About']);

    // The plain editor renders the parent control now, so a save carries it.
    $body = dispatch("/admin/pages/{$ids['Team']}/form")->body;
    assertContains('name="parent_id"', $body, 'the plain editor has no parent control');
    assertContains('value="' . $ids['About'] . '" selected', $body, 'it does not show the current parent');

    adminPost("/admin/pages/{$ids['Team']}", [
        'title' => 'Team',
        'slug' => 'team',
        'parent_id' => (string) $ids['About'],
        '_end' => '1',
    ]);

    assertEquals(['About', '-Team'], listingShape($db), 'the page left its parent on save');
});

testBothDrivers('each locale is ordered on its own', function (string $driver) {
    $db = installedSite(['en' => 'English', 'hr' => 'Hrvatski'], $driver);
    orderedPages($db, ['One' => null, 'Two' => null], 'en');
    orderedPages($db, ['Jedan' => null, 'Dva' => null], 'hr');

    $byLocale = [];
    foreach (PageTree::listing($db) as $row) {
        $byLocale[$row['locale']][] = $row['title'];
    }

    assertEquals(['One', 'Two'], $byLocale['en'], 'English order');
    assertEquals(['Jedan', 'Dva'], $byLocale['hr'], 'Croatian order');
});
