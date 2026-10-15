<?php

function queries($db, string $name, array $ids, string $table): void
{
    // ok: boxlet-sql-built
    $db->one('SELECT id FROM pages WHERE slug = ?', [$name]);
    $marks = implode(', ', array_fill(0, count($ids), '?'));
    // ok: boxlet-sql-built
    $db->all("SELECT id FROM media WHERE id IN ({$marks})", $ids);
    // ok: boxlet-sql-built
    $db->all("SELECT id FROM pages WHERE parent_id IN ($marks)", $ids);
    // ok: boxlet-sql-built
    $db->all('SELECT p.id, p.locale FROM pages p JOIN menus m ON m.locale = p.locale');
    // ruleid: boxlet-sql-built
    $db->one("SELECT id FROM pages WHERE slug = '$name'");
    // ruleid: boxlet-sql-built
    $db->one('SELECT id FROM pages WHERE slug = \'' . $name . '\'');
    // ruleid: boxlet-sql-built
    $db->query("DELETE FROM {$table}");
    // ruleid: boxlet-sql-built
    $db->all('SELECT * FROM pages ORDER BY ' . $name);
}

function notQueries($form, $snippets, $db): void
{
    // ok: boxlet-sql-built
    Form::all($db);
    // ok: boxlet-sql-built
    $snippets->one($id, $locale);
}
