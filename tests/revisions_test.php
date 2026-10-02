<?php

use App\Modules\Pages\PageRevision;

/*
 * What a page was before the last few publishes (PLAN.md D-088, D-173).
 *
 * Undo covers the editing session and dies with the tab; this covers what was on the site. A
 * revision is recorded when a published page is replaced by Publish, and restoring one puts it
 * into the draft, never straight onto the live page. adminSite() and adminPost() come from
 * pages_admin_test.php.
 */

/**
 * A page with one text block, and that block's id.
 *
 * @return array{0: int, 1: int}
 */
function pageWithBlock(App\Core\Db $db, string $heading = 'ORIGINAL', string $body = '<p>one</p>'): array
{
    $id = createPage($db, 'en', 'about', 'About', true, [
        ['type' => 'text', 'content' => ['heading' => $heading, 'body' => $body]],
    ]);

    return [$id, (int) ($db->one('SELECT id FROM page_blocks WHERE page_id = ?', [$id])['id'] ?? 0)];
}

/** @param array<string, mixed> $extra */
function saveBlock(int $pageId, int $blockId, string $heading, array $extra = []): App\Core\Response
{
    return adminPost("/admin/pages/{$pageId}", [
        'title' => 'About',
        'slug' => 'about',
       
        'action' => 'publish',
        '_end' => '1',
        'blocks' => [['id' => (string) $blockId, 'type' => 'text', 'heading' => $heading, 'body' => '<p>two</p>']],
    ] + $extra);
}

/** The heading of the page's draft, as the editors show it. */
function draftHeading(App\Core\Db $db, int $pageId): string
{
    $draft = App\Modules\Pages\PageDraft::find($db, blockRegistry(), $pageId);
    $heading = $draft === null ? null : (($draft['document']['blocks'][0]['content'] ?? [])['heading'] ?? null);

    return is_string($heading) ? $heading : '';
}

function storedHeading(App\Core\Db $db, int $blockId): string
{
    $content = storedContent($db, $blockId);

    return is_array($content) && is_string($content['heading'] ?? null) ? $content['heading'] : '';
}

testBothDrivers('Publish records what the page was, and restoring it brings that back into the draft', function (string $driver) {
    $db = adminSite($driver);
    [$id, $blockId] = pageWithBlock($db);

    assertEquals([], PageRevision::all($db, $id), 'a page that has never been republished has no history');

    assertEquals(302, saveBlock($id, $blockId, 'CHANGED')->status, 'the publish');
    assertEquals('CHANGED', storedHeading($db, $blockId), 'it wrote the new heading');
    $revisions = PageRevision::all($db, $id);
    assertEquals(1, count($revisions), 'one publish over a published page, one revision');

    $restore = adminPost("/admin/pages/{$id}", [
        'title' => 'About', 'slug' => 'about',
        'action' => 'restore-' . $revisions[0]['id'], '_end' => '1', 'blocks' => [],
    ]);
    assertEquals(302, $restore->status, 'the restore');
    assertEquals('CHANGED', storedHeading($db, $blockId), 'the site still has what was published');
    assertEquals('ORIGINAL', draftHeading($db, $id), 'the draft has the heading the page had before');

    // A restore changes nothing on the site, so it records nothing; the next Publish does.
    assertEquals(1, count(PageRevision::all($db, $id)), 'a restore records no revision');
    assertEquals(302, adminPost("/admin/pages/{$id}/status", ['status' => 'published'])->status, 'published again');
    assertEquals('ORIGINAL', storedHeading($db, $blockId), 'and it is the page again');
    assertEquals(2, count(PageRevision::all($db, $id)), 'with what it replaced kept');
});

test('the blocks on screen are discarded by a restore, not merged into it', function () {
    $db = adminSite('sqlite');
    [$id, $blockId] = pageWithBlock($db);
    assertEquals(302, saveBlock($id, $blockId, 'CHANGED')->status, 'the publish');
    $revision = PageRevision::all($db, $id)[0]['id'];

    // The form still holds an edit nobody saved. Restoring is a choice between two whole
    // pages, and keeping this would make it neither.
    adminPost("/admin/pages/{$id}", [
        'title' => 'Typed but not saved', 'slug' => 'about',
        'action' => 'restore-' . $revision, '_end' => '1',
        'blocks' => [['id' => (string) $blockId, 'type' => 'text', 'heading' => 'TYPED', 'body' => '<p>x</p>']],
    ]);

    assertEquals('ORIGINAL', draftHeading($db, $id), 'the typed heading went into the draft over the restore');
    $draft = App\Modules\Pages\PageDraft::find($db, blockRegistry(), $id) ?? fail('no draft');
    assertEquals('About', $draft['document']['title'], 'the typed title went into the draft over the restore');
});

test('a page keeps the last five and no more', function () {
    $db = adminSite('sqlite');
    [$id, $blockId] = pageWithBlock($db);
    for ($i = 0; $i < PageRevision::KEEP + 3; $i++) {
        saveBlock($id, $blockId, "H{$i}");
    }

    $revisions = PageRevision::all($db, $id);
    assertEquals(PageRevision::KEEP, count($revisions), 'revisions kept');
    // Newest first, so the newest is the one recorded by the last publish — the page as it
    // was before it, which is the heading written by the publish before that.
    $newest = PageRevision::find($db, blockRegistry(), $id, $revisions[0]['id'])
        ?? fail('the newest revision could not be read back');
    assertEquals('H' . (PageRevision::KEEP + 1), $newest['blocks'][0]['content']['heading'] ?? null, 'the newest revision');
});

test('a revision belongs to its page and cannot be poured into another', function () {
    $db = adminSite('sqlite');
    [$id, $blockId] = pageWithBlock($db);
    $other = createPage($db, 'en', 'contact', 'Contact', false, [['type' => 'text', 'content' => ['body' => '<p>theirs</p>']]]);
    saveBlock($id, $blockId, 'CHANGED');
    $mine = PageRevision::all($db, $id)[0]['id'];

    assertEquals(null, PageRevision::find($db, blockRegistry(), $other, $mine), 'found under the wrong page');

    $refused = adminPost("/admin/pages/{$other}", [
        'title' => 'Contact', 'slug' => 'contact',
        'action' => 'restore-' . $mine, '_end' => '1', 'blocks' => [],
    ]);
    assertEquals(422, $refused->status, 'restoring another page\'s revision');
    assertEquals(['text'], blockTypes($db, $other), 'the other page still has its own block');
});

testBothDrivers('a deleted page takes its history with it', function (string $driver) {
    $db = adminSite($driver);
    [$id, $blockId] = pageWithBlock($db);
    saveBlock($id, $blockId, 'CHANGED');
    assertEquals(1, count(PageRevision::all($db, $id)), 'a revision to delete');

    $db->query('DELETE FROM pages WHERE id = ?', [$id]);

    // ON DELETE CASCADE, which on SQLite works only because Db turns foreign keys on.
    assertEquals(
        0,
        (int) ($db->one('SELECT COUNT(*) AS n FROM page_revisions WHERE page_id = ?', [$id])['n'] ?? -1),
        'revisions left behind by a deleted page',
    );
});

test('a block whose type has gone since is left out rather than restored as a hole', function () {
    $db = adminSite('sqlite');
    [$id, $blockId] = pageWithBlock($db);
    saveBlock($id, $blockId, 'CHANGED');
    $revision = PageRevision::all($db, $id)[0]['id'];

    // A revision written when the site had a block this install no longer knows.
    $row = $db->one('SELECT data_json FROM page_revisions WHERE id = ?', [$revision]) ?? fail('the revision row is gone');
    $data = json_decode((string) $row['data_json'], true);
    $data['blocks'][] = ['id' => null, 'type' => 'gone_block', 'content' => [], 'style' => [], 'layout' => ''];
    $db->query('UPDATE page_revisions SET data_json = ? WHERE id = ?', [json_encode($data), $revision]);

    $found = PageRevision::find($db, blockRegistry(), $id, $revision)
        ?? fail('the revision could not be read back at all');
    assertEquals(1, count($found['blocks']), 'the unknown block was carried into the restore');
    assertEquals('text', $found['blocks'][0]['type'], 'the block that is still known');
});

test('a revision row that is not JSON, or not a page, is refused rather than half-applied', function () {
    $db = adminSite('sqlite');
    [$id, $blockId] = pageWithBlock($db);
    saveBlock($id, $blockId, 'CHANGED');
    $revision = PageRevision::all($db, $id)[0]['id'];

    foreach (['not json at all', '{"title":"no blocks key"}', 'null'] as $broken) {
        $db->query('UPDATE page_revisions SET data_json = ? WHERE id = ?', [$broken, $revision]);
        assertEquals(null, PageRevision::find($db, blockRegistry(), $id, $revision), "accepted {$broken}");
    }
});
