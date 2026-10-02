<?php

use App\Core\Session;
use App\Modules\Pages\PageDocument;
use App\Modules\Pages\PageDraft;

// Drafts (PLAN.md D-173, README 2.1–2.2): Save keeps a draft, Publish puts it on the site,
// Discard goes back to what is published, a revision comes back into the draft, and the
// builder's autosave is one JSON document with a version.

/**
 * A published page of one text block, and its block's id.
 *
 * @return array{int, int}
 */
function publishedTextPage(App\Core\Db $db, string $words = '<p>Live words</p>'): array
{
    $id = createPage($db, 'en', 'news', 'News', true, [['type' => 'text', 'content' => ['heading' => 'News', 'body' => $words]]]);

    return [$id, (int) ($db->one('SELECT id FROM page_blocks WHERE page_id = ?', [$id])['id'] ?? 0)];
}

/**
 * The editor's form for that page with new words, as either editor sends it.
 *
 * @return array<string, mixed>
 */
function editedText(int $blockId, string $words, string $action, int $version = 0): array
{
    return [
        'title' => 'News',
        'slug' => 'news',
        'blocks' => [['id' => (string) $blockId, 'type' => 'text', 'heading' => 'News', 'body' => $words]],
        'action' => $action,
        'draft_version' => (string) $version,
        '_end' => '1',
    ];
}

/**
 * A JSON request to the draft endpoint, its token in the header as the builder sends it.
 *
 * @param array<string, mixed> $body
 */
function draftRequest(int $pageId, array $body, ?string $token = null): App\Core\Response
{
    $saved = $_SERVER;
    $_SERVER['CONTENT_TYPE'] = 'application/json';
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $token ?? (new Session())->csrfToken();
    try {
        return dispatch("/admin/pages/{$pageId}/draft", null, 'POST', $body);
    } finally {
        $_SERVER = $saved;
    }
}

testBothDrivers('Save keeps a draft: the published page does not change until Publish', function (string $driver) {
    $db = adminSite($driver);
    [$id, $block] = publishedTextPage($db);

    assertRedirectedTo("/admin/pages/{$id}", adminPost("/admin/pages/{$id}", editedText($block, '<p>Draft words</p>', 'save')));
    assertContains('Live words', dispatch('/news')->body, 'a visitor sees the published page');
    assertTrue(!str_contains(dispatch('/news')->body, 'Draft words'), 'the draft is not on the site');
    assertEquals('changes', PageDraft::state($db, App\Modules\Pages\Page::find($db, $id) ?? []), 'the bar says there are unpublished changes');
    assertContains('Draft words', dispatch("/admin/pages/{$id}/form")->body, 'the editor shows the draft');
    assertContains('Draft words', dispatch("/admin/pages/{$id}/canvas")->body, 'and so does the canvas');

    assertRedirectedTo("/admin/pages/{$id}", adminPost("/admin/pages/{$id}", editedText($block, '<p>Draft words</p>', 'publish', 1)));
    assertContains('Draft words', dispatch('/news')->body, 'published');
    assertEquals(false, PageDraft::exists($db, $id), 'the draft is gone once published');
    assertEquals($block, (int) ($db->one('SELECT id FROM page_blocks WHERE page_id = ?', [$id])['id'] ?? 0), 'the block was updated in place, not replaced');
    assertEquals(1, count(App\Modules\Pages\PageRevision::all($db, $id)), 'what it was is a revision');
});

testBothDrivers('Discard goes back to the published page, and is no answer for a page never published', function (string $driver) {
    $db = adminSite($driver);
    [$id, $block] = publishedTextPage($db);
    adminPost("/admin/pages/{$id}", editedText($block, '<p>Draft words</p>', 'save'));
    assertContains(e(t('pages.discard')), dispatch("/admin/pages/{$id}/form")->body, 'offered with a draft over a published page');

    adminPost("/admin/pages/{$id}", editedText($block, '<p>Draft words</p>', 'discard', 1));
    assertEquals(false, PageDraft::exists($db, $id), 'the draft is gone');
    assertContains('Live words', dispatch("/admin/pages/{$id}/form")->body, 'the editor is back on the published page');

    $new = createPage($db, 'en', 'fresh', 'Fresh', false, [['type' => 'text', 'content' => ['body' => '<p>Never out</p>']]]);
    $newBlock = (int) ($db->one('SELECT id FROM page_blocks WHERE page_id = ?', [$new])['id'] ?? 0);
    adminPost("/admin/pages/{$new}", ['slug' => 'fresh', 'title' => 'Fresh'] + editedText($newBlock, '<p>Still drafting</p>', 'save'));
    assertTrue(!str_contains(dispatch("/admin/pages/{$new}/form")->body, e(t('pages.discard'))), 'not offered for a page never published');
    adminPost("/admin/pages/{$new}", ['slug' => 'fresh', 'title' => 'Fresh'] + editedText($newBlock, '<p>Still drafting</p>', 'discard', 1));
    assertEquals(true, PageDraft::exists($db, $new), 'and refused if asked anyway');
});

testBothDrivers('a save from a version the draft has moved past is refused, and nothing is written', function (string $driver) {
    $db = adminSite($driver);
    [$id, $block] = publishedTextPage($db);
    adminPost("/admin/pages/{$id}", editedText($block, '<p>First tab</p>', 'save', 0));

    $stale = adminPost("/admin/pages/{$id}", editedText($block, '<p>Second tab</p>', 'save', 0));
    assertEquals(422, $stale->status, 'the second tab, made from no draft');
    assertContains(e(t('pages.draft.conflict')), $stale->body, 'and told why');
    assertContains('First tab', (string) json_encode(PageDraft::find($db, blockRegistry(), $id)), 'the first tab\'s words are kept');
});

testBothDrivers('Unpublish keeps what was published as the draft', function (string $driver) {
    $db = adminSite($driver);
    [$id] = publishedTextPage($db);

    adminPost("/admin/pages/{$id}/status", ['status' => 'draft']);
    assertEquals('draft', (string) ($db->one('SELECT status FROM pages WHERE id = ?', [$id])['status'] ?? ''), 'off the site');
    assertEquals(404, dispatch('/news')->status, 'a visitor no longer finds it');
    $draft = PageDraft::find($db, blockRegistry(), $id) ?? fail('no draft');
    assertContains('Live words', (string) json_encode($draft['document']), 'the page is the draft now');

    adminPost("/admin/pages/{$id}/status", ['status' => 'published']);
    assertContains('Live words', dispatch('/news')->body, 'and Publish puts the same page back');
});

testBothDrivers('Publish refuses a draft with no title, and keeps it', function (string $driver) {
    $db = adminSite($driver);
    [$id, $block] = publishedTextPage($db);
    $document = PageDraft::current($db, blockRegistry(), $id)['document'] ?? fail('no document');
    $document['title'] = '';
    PageDraft::save($db, $id, $document, 0);

    adminPost("/admin/pages/{$id}/status", ['status' => 'published']);
    assertEquals('News', (string) ($db->one('SELECT title FROM pages WHERE id = ?', [$id])['title'] ?? ''), 'the published title stands');
    assertEquals(true, PageDraft::exists($db, $id), 'the draft is kept to be fixed');
    assertContains(t('pages.title_required'), (string) ($_SESSION['flash'] ?? ''), 'and the owner is told what stopped it');
});

testBothDrivers('a revision is restored into the draft, never onto the live page', function (string $driver) {
    $db = adminSite($driver);
    [$id, $block] = publishedTextPage($db, '<p>Monday</p>');
    adminPost("/admin/pages/{$id}", editedText($block, '<p>Tuesday</p>', 'publish'));
    $revision = App\Modules\Pages\PageRevision::all($db, $id)[0]['id'] ?? fail('no revision');

    assertRedirectedTo("/admin/pages/{$id}", adminPost("/admin/pages/{$id}", editedText($block, '<p>Tuesday</p>', 'restore-' . $revision)));
    assertContains('Tuesday', dispatch('/news')->body, 'the site still has what was published');
    assertContains('Monday', dispatch("/admin/pages/{$id}/form")->body, 'the draft has what it was');
});

testBothDrivers('the builder\'s autosave is one JSON document, versioned, its token in a header', function (string $driver) {
    $db = adminSite($driver);
    [$id, $block] = publishedTextPage($db);
    $start = json_decode(dispatch("/admin/pages/{$id}/draft")->body, true);
    assertEquals(0, $start['version'] ?? null, 'no draft yet');
    assertEquals('published', $start['state'] ?? null, 'the page is published');

    $document = $start['document'];
    $document['blocks'][0]['content']['body'] = '<p>Autosaved</p><script>x</script>';
    $saved = draftRequest($id, ['version' => 0, 'document' => $document]);
    assertEquals(200, $saved->status, 'saved');
    assertEquals(1, json_decode($saved->body, true)['version'] ?? null, 'version 1');
    $draft = PageDraft::find($db, blockRegistry(), $id) ?? fail('no draft');
    $body = (string) ($draft['document']['blocks'][0]['content']['body'] ?? '');
    assertContains('<p>Autosaved</p>', $body, 'the words kept');
    assertTrue(!str_contains($body, '<script'), 'through the same sanitiser as a form');
    assertContains('Live words', dispatch('/news')->body, 'nothing a visitor sees changed');

    $conflict = draftRequest($id, ['version' => 0, 'document' => $document]);
    assertEquals(409, $conflict->status, 'a save from an older version');
    assertEquals(1, json_decode($conflict->body, true)['version'] ?? null, 'and the draft\'s own version, to reload');

    assertEquals(403, draftRequest($id, ['version' => 1, 'document' => $document], 'not-the-token')->status, 'a wrong token');
    assertEquals(422, draftRequest($id, ['version' => 1, 'document' => 'nonsense'])->status, 'a body that is no document');
});

test('a document read from JSON is rebuilt against the registry, and keeps a stored block it cannot draw', function () {
    $registry = blockRegistry();
    $document = PageDocument::read($registry, [
        'title' => ' Title ',
        'sections' => [['key' => 's1', 'id' => 1, 'style' => ['surface' => 'neon', 'anchor' => 'top']], ['key' => 'm1', 'style' => ['anchor' => 'top']]],
        'blocks' => [
            ['key' => 'b5', 'id' => 5, 'type' => 'text', 'content' => ['body' => '<p>Hi</p>'], 'section' => 's1'],
            ['key' => 'b5', 'type' => 'text', 'content' => [], 'section' => 'm1'],
            ['key' => 'b9', 'id' => 9, 'type' => 'gone', 'content' => ['x' => 1], 'section' => 's1'],
            ['type' => 'gone', 'section' => 's1'],
            ['type' => 'hero', 'section' => 'nowhere'],
        ],
    ]) ?? fail('not read');

    assertEquals('Title', $document['title'], 'the title trimmed');
    assertEquals('', $document['sections'][0]['style']['surface'], 'a surface that is none is the character\'s');
    assertEquals(['top', 'top-2'], array_map(static fn (array $s): mixed => $s['style']['anchor'], array_slice($document['sections'], 0, 2)), 'every anchor once');
    assertEquals(['b5', 'n1001', 'b9', 'n1003'], array_column($document['blocks'], 'key'), 'a key used twice is minted anew; an unknown type never stored is left out');
    assertEquals(null, $document['blocks'][2]['content'], 'a stored block of a type this install lacks keeps its row, with no content to write');
    assertEquals(3, count($document['sections']), 'a block naming no band gets one of its own');
    assertEquals(null, PageDocument::read($registry, ['blocks' => []]), 'no bands, no document');
});
