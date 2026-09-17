<?php

// The menus schema (PLAN.md D-028, migration 0015), and the one rule it encodes rather
// than leaves to code: what happens to a menu item when the page it points at is deleted.
//
// Measured here on both drivers because it is a claim about foreign keys, and foreign keys
// are exactly where the two engines differ. SQLite enforces them only when
// PRAGMA foreign_keys is on — Db turns it on — and MySQL only under an engine that
// supports them. A migration comment stating a rule neither engine applies would be worse
// than no comment, so the rule is asserted rather than described.

/**
 * A locale, a page and a menu item pointing at it. Returns [pageId, itemId].
 *
 * @return array{int, int}
 */
function menuFixture(App\Core\Db $db, string $slug = 'about'): array
{
    $now = gmdate('Y-m-d H:i:s');
    if ($db->one('SELECT code FROM locales WHERE code = ?', ['en']) === null) {
        $db->query('INSERT INTO locales (code, label, is_primary, sort, enabled) VALUES (?, ?, 1, 0, 1)', ['en', 'English']);
    }
    $db->query(
        'INSERT INTO pages (locale, slug, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
        ['en', $slug, 'About', 'published', $now, $now],
    );
    $pageId = (int) $db->lastInsertId();

    $db->query('INSERT INTO menus (locale, name, created_at, updated_at) VALUES (?, ?, ?, ?)', ['en', 'Header ' . $slug, $now, $now]);
    $menuId = (int) $db->lastInsertId();

    $db->query(
        'INSERT INTO menu_items (menu_id, page_id, label, sort, created_at, updated_at) VALUES (?, ?, ?, 0, ?, ?)',
        [$menuId, $pageId, 'About us', $now, $now],
    );

    return [$pageId, (int) $db->lastInsertId()];
}

testBothDrivers('deleting a page empties the menu item that pointed at it, and keeps the item', function (string $driver) {
    $db = migratedDatabase($driver);
    [$pageId, $itemId] = menuFixture($db);

    $db->query('DELETE FROM pages WHERE id = ?', [$pageId]);

    $item = $db->one('SELECT page_id, label FROM menu_items WHERE id = ?', [$itemId]);
    if ($item === null) {
        fail('the menu item was deleted with the page: that is CASCADE, and this column is SET NULL');
    }
    // The item a person built survives, so they can repoint it. What goes is the link.
    assertEquals(null, $item['page_id'], 'page_id after the page was deleted');
    assertEquals('About us', (string) $item['label'], 'the label the owner typed');
});

testBothDrivers('deleting a menu takes its items with it', function (string $driver) {
    $db = migratedDatabase($driver);
    [, $itemId] = menuFixture($db);
    $menuId = (int) ($db->one('SELECT menu_id FROM menu_items WHERE id = ?', [$itemId])['menu_id'] ?? 0);

    $db->query('DELETE FROM menus WHERE id = ?', [$menuId]);

    // The opposite choice from page_id, and deliberately: an item has no meaning without
    // the menu it belongs to, while it keeps its meaning without the page it pointed at.
    assertEquals(null, $db->one('SELECT id FROM menu_items WHERE id = ?', [$itemId]), 'the item outlived its menu');
});

testBothDrivers('a submenu item goes when its parent does', function (string $driver) {
    $db = migratedDatabase($driver);
    [, $parentId] = menuFixture($db);
    $menuId = (int) ($db->one('SELECT menu_id FROM menu_items WHERE id = ?', [$parentId])['menu_id'] ?? 0);
    $now = gmdate('Y-m-d H:i:s');
    $db->query(
        'INSERT INTO menu_items (menu_id, parent_id, url, label, sort, created_at, updated_at) VALUES (?, ?, ?, ?, 0, ?, ?)',
        [$menuId, $parentId, '/contact', 'Contact', $now, $now],
    );
    $childId = (int) $db->lastInsertId();

    $db->query('DELETE FROM menu_items WHERE id = ?', [$parentId]);

    assertEquals(null, $db->one('SELECT id FROM menu_items WHERE id = ?', [$childId]), 'the child outlived its parent');
});

testBothDrivers('two menus in one locale cannot share a name, and two locales can', function (string $driver) {
    $db = migratedDatabase($driver);
    $now = gmdate('Y-m-d H:i:s');
    $db->query('INSERT INTO locales (code, label, is_primary, sort, enabled) VALUES (?, ?, 1, 0, 1)', ['en', 'English']);
    $db->query('INSERT INTO locales (code, label, is_primary, sort, enabled) VALUES (?, ?, 0, 1, 1)', ['hr', 'Hrvatski']);
    $db->query('INSERT INTO menus (locale, name, created_at, updated_at) VALUES (?, ?, ?, ?)', ['en', 'Header', $now, $now]);

    // The same name in another locale is the normal case: a menu exists per locale so a
    // translation has its own labels.
    $db->query('INSERT INTO menus (locale, name, created_at, updated_at) VALUES (?, ?, ?, ?)', ['hr', 'Header', $now, $now]);
    assertEquals(2, count($db->all('SELECT id FROM menus')), 'one menu per locale with the same name');

    // Asserted on the phrase both engines use, not on the value. MySQL names the duplicate
    // ("Duplicate entry 'en-Header'"), SQLite names the columns and no value at all — so a
    // test looking for 'Header' passes on one engine and fails on the other, which is the
    // portability trap SPEC §5.0 is about. SQLSTATE 23000 reads the same either side.
    assertThrows(
        static fn () => $db->query('INSERT INTO menus (locale, name, created_at, updated_at) VALUES (?, ?, ?, ?)', ['en', 'Header', $now, $now]),
        'Integrity constraint violation',
    );
});
