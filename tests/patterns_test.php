<?php

use App\Modules\Design\Composition;
use App\Modules\Pages\PageDraft;
use App\Modules\Pages\PagePattern;

// Patterns (PLAN.md D-173, README 2.3): a section kept to be used again, and put into a
// page's draft as a deep copy; a design set's offered from the site's character.

testBothDrivers('a section saved as a pattern is put into another page\'s draft as a copy of its own', function (string $driver) {
    $db = adminSite($driver);
    $from = createPage($db, 'en', 'from', 'From', true, [['type' => 'text', 'content' => ['heading' => 'Kept', 'body' => '<p>Kept words</p>'], 'style' => ['surface' => 'tinted', 'anchor' => 'kept']]]);
    $block = (int) ($db->one('SELECT id FROM page_blocks WHERE page_id = ?', [$from])['id'] ?? 0);
    $section = 's' . (int) ($db->one('SELECT section_id FROM page_blocks WHERE id = ?', [$block])['section_id'] ?? 0);

    $saved = adminPost("/admin/pages/{$from}", [
        'title' => 'From', 'slug' => 'from', '_end' => '1', 'draft_version' => '0',
        // The band's style travels on its block in a form that sends no sections (oneEach).
        'blocks' => [['id' => (string) $block, 'type' => 'text', 'heading' => 'Kept', 'body' => '<p>Kept words</p>', 'style' => ['surface' => 'tinted', 'anchor' => 'kept']]],
        'action' => 'pattern-save', 'pattern_section' => $section, 'pattern_name' => 'Tinted words',
    ]);
    assertRedirectedTo("/admin/pages/{$from}", $saved);
    $mine = PagePattern::mine($db);
    assertEquals(['Tinted words'], array_column($mine, 'name'), 'kept under its name');

    $to = createPage($db, 'en', 'target', 'Target', true, [['type' => 'hero', 'content' => ['heading' => 'Top']]]);
    $hero = (int) ($db->one('SELECT id FROM page_blocks WHERE page_id = ?', [$to])['id'] ?? 0);
    assertRedirectedTo("/admin/pages/{$to}", adminPost("/admin/pages/{$to}", [
        'title' => 'Target', 'slug' => 'target', '_end' => '1', 'draft_version' => '0',
        'blocks' => [['id' => (string) $hero, 'type' => 'hero', 'heading' => 'Top']],
        'action' => 'pattern-insert', 'pattern' => 'user:' . $mine[0]['id'],
    ]));
    $draft = PageDraft::find($db, blockRegistry(), $to) ?? fail('no draft');
    assertEquals(['hero', 'text'], array_column($draft['document']['blocks'], 'type'), 'at the end of the page');
    $copy = $draft['document']['blocks'][1];
    assertEquals(null, $copy['id'], 'a new block, nothing tied to where it came from');
    assertEquals('Kept words', strip_tags((string) (($copy['content'] ?? [])['body'] ?? '')), 'with what it said');
    $band = array_values(array_filter($draft['document']['sections'], static fn (array $s): bool => $s['key'] === $copy['section']))[0] ?? fail('no band');
    assertEquals(null, $band['id'], 'in a new band');
    assertEquals('tinted', $band['style']['surface'], 'styled as it was');
    assertEquals('', $band['style']['anchor'], 'and without the anchor that named a place on the other page');
    assertEquals(['hero'], blockTypes($db, $to), 'nothing on the published page until Publish');
});

testBothDrivers('the design set\'s patterns are offered in the page\'s language, English where it has none', function (string $driver) {
    $db = adminSite($driver);
    Composition::remember($db, 'soft');
    $set = PagePattern::fromSet('soft', 'hr');
    assertTrue(in_array('Tri kartice', array_column($set, 'name'), true), 'named in Croatian');

    $pattern = PagePattern::find($db, blockRegistry(), 'set:three-cards', 'soft', 'de') ?? fail('no pattern');
    assertEquals('cards', $pattern['blocks'][0]['type'], 'its block');
    assertEquals('Cards', $pattern['blocks'][0]['content']['heading'] ?? null, 'its words in English for a language the set does not have');
    assertEquals(null, PagePattern::find($db, blockRegistry(), 'set:nothing-of-the-kind', 'soft', 'en'), 'a pattern the set does not have');
    assertEquals(null, PagePattern::find($db, blockRegistry(), 'user:999', 'soft', 'en'), 'or one deleted');
});

testBothDrivers('a page never published is translated from its draft; a published one from what is published', function (string $driver) {
    $db = adminSite($driver);
    $draftOnly = createPage($db, 'en', 'soon', 'Soon', false, [['type' => 'text', 'content' => ['body' => '<p>First words</p>']]]);
    $block = (int) ($db->one('SELECT id FROM page_blocks WHERE page_id = ?', [$draftOnly])['id'] ?? 0);
    adminPost("/admin/pages/{$draftOnly}", ['title' => 'Soon', 'slug' => 'soon', '_end' => '1', 'draft_version' => '0', 'action' => 'save', 'blocks' => [['id' => (string) $block, 'type' => 'text', 'body' => '<p>Drafted words</p>']]]);

    adminPost("/admin/pages/{$draftOnly}/translate", ['locale' => 'hr']);
    $copy = (int) ($db->one("SELECT id FROM pages WHERE locale = 'hr'")['id'] ?? 0);
    $draft = PageDraft::find($db, blockRegistry(), $copy) ?? fail('the translation has no draft');
    assertContains('Drafted words', (string) json_encode($draft['document']), 'it starts from the draft');
    $copied = (int) ($db->one('SELECT id FROM page_blocks WHERE page_id = ?', [$copy])['id'] ?? 0);
    assertEquals($copied, $draft['document']['blocks'][0]['id'], 'pointing at its own copy of the block, so the two stay paired');

    [$live, $liveBlock] = publishedTextPage($db);
    adminPost("/admin/pages/{$live}", editedText($liveBlock, '<p>Unpublished</p>', 'save'));
    assertContains(e(t('translations.unpublished_stay')), dispatch("/admin/pages/{$live}")->body, 'the menu says the changes stay behind');
    adminPost("/admin/pages/{$live}/translate", ['locale' => 'hr']);
    $liveCopy = (int) ($db->one("SELECT id FROM pages WHERE locale = 'hr' AND slug = 'news'")['id'] ?? 0);
    assertEquals(false, PageDraft::exists($db, $liveCopy), 'a published page\'s translation has no draft');
});

testBothDrivers('applying a character hands the drafts back too, never their words', function (string $driver) {
    $db = adminSite($driver);
    [$id, $block] = publishedTextPage($db);
    $document = PageDraft::current($db, blockRegistry(), $id)['document'] ?? fail('no document');
    $document['sections'][0]['style']['surface'] = 'contrast';
    $document['blocks'][0]['content']['body'] = '<p>Drafted</p>';
    PageDraft::save($db, $id, App\Modules\Pages\PageDocument::read(blockRegistry(), $document) ?? fail('not a document'), 0);

    Composition::apply($db, blockRegistry(), 'soft');
    $draft = PageDraft::find($db, blockRegistry(), $id) ?? fail('no draft');
    assertEquals('', $draft['document']['sections'][0]['style']['surface'], 'the surface is the character\'s again');
    assertContains('Drafted', (string) (($draft['document']['blocks'][0]['content'] ?? [])['body'] ?? ''), 'the words stay');
    assertEquals(2, $draft['version'], 'and the draft moved on, so a tab still open on it is told');
});
