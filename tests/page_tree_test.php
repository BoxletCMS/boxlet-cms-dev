<?php

use App\Core\Db;
use App\Modules\Pages\PageTree;

// The page hierarchy behind the parent selector. A cycle is not a display problem: every
// later reader of the tree — breadcrumbs, menus, an address built from ancestors — would
// have to defend against looping for ever, so the selector never offers one.

/**
 * @param array<string, string|null> $tree child title => parent title
 * @return array<string, int> title => id
 */
function pagesWithParents(Db $db, array $tree): array
{
    $ids = [];
    foreach (array_keys($tree) as $title) {
        $ids[$title] = createPage($db, 'en', strtolower((string) $title), (string) $title);
    }
    foreach ($tree as $title => $parent) {
        if ($parent !== null) {
            $db->query('UPDATE pages SET parent_id = ? WHERE id = ?', [$ids[$parent], $ids[$title]]);
        }
    }

    return $ids;
}

testBothDrivers('the parent selector offers the tree in order, with depth', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $ids = pagesWithParents($db, ['About' => null, 'Team' => 'About', 'Ada' => 'Team', 'Services' => null]);

    $options = PageTree::parentOptions($db, 'en', null);
    $shape = array_map(static fn (array $o): string => str_repeat('-', $o['depth']) . $o['title'], $options);

    assertEquals(['About', '-Team', '--Ada', 'Services'], $shape, 'tree order and depth');
    assertEquals($ids['Team'], $options[1]['id'], 'the id travels with the option');
});

testBothDrivers('a page is never offered itself or one of its own descendants', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $ids = pagesWithParents($db, ['About' => null, 'Team' => 'About', 'Ada' => 'Team', 'Services' => null]);

    $titles = array_column(PageTree::parentOptions($db, 'en', $ids['About']), 'title');

    // Parenting About to Team or Ada would make a cycle; to Services would not.
    assertEquals(['Services'], $titles, 'options for About');
    assertEquals(['About', 'Services'], array_column(PageTree::parentOptions($db, 'en', $ids['Team']), 'title'), 'options for Team');
});

test('the tree of another locale is never offered', function () {
    $db = installedSite(['en' => 'English', 'hr' => 'Hrvatski']);
    createPage($db, 'en', 'about', 'About');
    createPage($db, 'hr', 'o-nama', 'O nama');

    assertEquals(['About'], array_column(PageTree::parentOptions($db, 'en', null), 'title'), 'English');
    assertEquals(['O nama'], array_column(PageTree::parentOptions($db, 'hr', null), 'title'), 'Croatian');
});

test('a cycle already in the database does not hang the selector', function () {
    $db = installedSite(['en' => 'English']);
    $ids = pagesWithParents($db, ['One' => null, 'Two' => 'One']);
    // Written by hand, or by a version that allowed it: One is now its own grandchild.
    $db->query('UPDATE pages SET parent_id = ? WHERE id = ?', [$ids['Two'], $ids['One']]);

    $options = PageTree::parentOptions($db, 'en', $ids['One']);

    assertTrue(count($options) <= 2, 'the walk did not terminate cleanly');
    assertTrue(!in_array($ids['One'], array_column($options, 'id'), true), 'a page was offered itself');
});
