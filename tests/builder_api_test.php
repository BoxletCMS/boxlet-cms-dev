<?php

use App\Core\Session;
use App\Modules\Pages\PageDraft;
use App\Modules\Pages\PagePattern;

// The builder's server half (PLAN.md D-175, README 4): the document lives in the browser, and
// the server draws one band of it (/render), draws the inspector for what is selected
// (/inspect), cleans one block's fields (/fields), and publishes, discards and restores as
// JSON. None of the drawing endpoints writes anything; every one wants the token in its
// header. The shell itself is tests/builder_test.php.

/**
 * A JSON request as builder-doc.js sends one: the body JSON, the token in X-CSRF-Token.
 *
 * @param array<string, mixed> $body
 */
function builderRequest(string $path, array $body, ?string $token = null): App\Core\Response
{
    $saved = $_SERVER;
    $_SERVER['CONTENT_TYPE'] = 'application/json';
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $token ?? (new Session())->csrfToken();
    try {
        return dispatch($path, null, 'POST', $body);
    } finally {
        $_SERVER = $saved;
    }
}

/**
 * The page's document as the builder starts from it, and one band of it.
 *
 * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: list<array<string, mixed>>}
 */
function builderBand(int $pageId, int $index = 0): array
{
    $document = json_decode(dispatch("/admin/pages/{$pageId}/draft")->body, true)['document'] ?? fail('no document');
    $section = $document['sections'][$index] ?? fail('no band ' . $index);
    $blocks = array_values(array_filter($document['blocks'], static fn (array $b): bool => $b['section'] === $section['key']));

    return [$document, $section, $blocks];
}

testBothDrivers('a band is drawn from the document sent, exactly as the canvas draws it, and nothing is written', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', true, [['type' => 'text', 'content' => ['body' => '<p>Stored</p>']]]);
    $before = $db->all('SELECT * FROM page_blocks ORDER BY id');
    [, $section, $blocks] = builderBand($id);

    $canvas = dispatch("/admin/pages/{$id}/canvas")->body;
    $drawn = json_decode(builderRequest("/admin/pages/{$id}/render", ['section' => $section, 'blocks' => $blocks])->body, true)['html'] ?? fail('no html');
    $band = static fn (string $html): string => (string) preg_replace('~\s+~', ' ', trim($html));
    assertContains($band($drawn), $band($canvas), 'the band drawn alone is the band the canvas holds');
    assertContains('data-bx-section="' . $section['key'] . '"', $drawn, 'the band is named');
    assertContains('data-bx-key="' . $blocks[0]['key'] . '"', $drawn, 'its block is named');

    // What is being edited, unsaved and unsafe: drawn clean, and still nothing written.
    $blocks[0]['content']['body'] = '<p onclick="alert(1)">Typed<script>alert(2)</script></p>';
    $section['style']['surface'] = 'not-a-surface';
    $typed = json_decode(builderRequest("/admin/pages/{$id}/render", ['section' => $section, 'blocks' => $blocks])->body, true)['html'] ?? '';
    assertContains('Typed', $typed, 'the words drawn');
    assertTrue(!str_contains($typed, 'alert('), 'an event attribute or a script reached the canvas');
    assertTrue(!str_contains($typed, 'surface-not-a-surface'), 'an invalid surface was drawn');
    assertEquals($before, $db->all('SELECT * FROM page_blocks ORDER BY id'), 'drawing wrote to the page');
    assertEquals(false, PageDraft::exists($db, $id), 'drawing made a draft');
});

test('every drawing endpoint wants the token, and a body that is a band', function () {
    $id = builderPage();
    [, $section, $blocks] = builderBand($id);
    foreach (['render', 'inspect', 'fields', 'publish', 'discard', 'restore'] as $endpoint) {
        assertEquals(403, builderRequest("/admin/pages/{$id}/{$endpoint}", ['section' => $section, 'blocks' => $blocks], 'not-the-token')->status, "{$endpoint} without the token");
    }
    assertEquals(422, builderRequest("/admin/pages/{$id}/render", ['section' => 'nonsense'])->status, 'a body that is no band');
    assertEquals(422, builderRequest("/admin/pages/{$id}/inspect", ['kind' => 'block', 'key' => 'nobody', 'section' => $section, 'blocks' => $blocks])->status, 'a block not in the band');
});

testBothDrivers('the section inspector has its five groups and its actions, every control named by where its value lives', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', true, [['type' => 'text', 'content' => ['body' => '<p>Words</p>'], 'style' => ['anchor' => 'words']]]);
    [, $section, $blocks] = builderBand($id);
    $html = json_decode(builderRequest("/admin/pages/{$id}/inspect", ['kind' => 'section', 'key' => $section['key'], 'section' => $section, 'blocks' => $blocks, 'number' => 1])->body, true)['html'] ?? fail('no inspector');

    foreach (['columns', 'background', 'spacing', 'content', 'advanced'] as $group) {
        assertContains(e(t('builder.group.' . $group)), $html, "the {$group} group");
    }
    foreach (['s.layout', 's.style.surface', 's.style.pad_top', 's.style.width', 's.style.anchor', 's.style.hide_mobile', 's.style.animation'] as $name) {
        assertContains('name="' . $name . '"', $html, $name);
    }
    foreach (['pattern', 'duplicate', 'delete'] as $action) {
        assertContains('data-action="' . $action . '"', $html, "the {$action} action");
    }
    // A padding left to the character stands at the real value, the design's section gap, and
    // its readout says it is the character's (D-176): the dot only once the slider is moved.
    $gap = App\Modules\Design\Tokens::readable(App\Modules\Design\Design::resolved($db))['section'];
    assertContains('name="s.style.pad_top" min="0" max="200" step="4" value="' . $gap . '"', $html, 'the slider at the section gap');
    assertTrue((bool) preg_match('~data-control="s.style.pad_top">.*?<span class="readout"[^>]*data-from="character"[^>]*>' . $gap . ' px</span>~s', $html), 'its readout, said as the character\'s');
    assertTrue((bool) preg_match('~<div class="control-row" data-control="s.style.pad_top">~', $html), 'and no dot');

    // The anchor it has, as a link to it; and no key of the document anywhere a person reads.
    assertContains('#words', $html, 'the anchor said as a link');
    assertTrue(!str_contains(strip_tags($html), $section['key']), 'the band\'s internal key was shown');
});

testBothDrivers('the block inspector: its layouts, options, the fields not on the page, all content folded, and the way to its band', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', true, [['type' => 'hero', 'content' => ['heading' => 'Welcome']]]);
    [, $section, $blocks] = builderBand($id);
    $key = $blocks[0]['key'];
    $html = json_decode(builderRequest("/admin/pages/{$id}/inspect", ['kind' => 'block', 'key' => $key, 'section' => $section, 'blocks' => $blocks, 'number' => 1])->body, true)['html'] ?? fail('no inspector');

    assertContains('data-block data-block-fields="' . $key . '"', $html, 'one form for the block, marked as a block for the scripts that look for one');
    assertContains('name="blocks[' . $key . '][layout]"', $html, 'the layout tiles');
    assertContains('name="blocks[' . $key . '][options][height]"', $html, 'an option');
    assertContains(e(t('builder.group.not_shown')), $html, 'the fields not shown on the page');
    assertContains('name="blocks[' . $key . '][image]"', $html, 'the picture is among them');
    // All content is folded: a <details> without `open`, holding the heading's field.
    if (!preg_match('~<details class="[^"]*" id="ins-content"[^>]*>~', $html, $content)) {
        fail('no All content group');
    }
    assertTrue(!str_contains($content[0], ' open'), 'All content arrived open');
    assertContains('name="blocks[' . $key . '][heading]" value="Welcome"', $html, 'the words, typed in the inspector in this phase');
    assertContains('data-action="select-section"', $html, 'the way to its band');
    assertTrue(!str_contains(strip_tags($html), $key), 'the block\'s internal key was shown');
});

testBothDrivers('a block\'s fields are cleaned by the form\'s own parser, with its errors by field', function (string $driver) {
    $id = builderPage($driver);
    $clean = dispatch("/admin/pages/{$id}/fields", null, 'POST', ['_csrf' => (new Session())->csrfToken(), 'blocks' => ['n4' => [
        'type' => 'text', 'id' => '99', 'body' => '<p onclick="x()">Kept<script>y()</script></p>',
    ]]]);
    $answer = json_decode($clean->body, true);
    assertEquals(200, $clean->status, 'answered');
    assertContains('Kept', (string) ($answer['block']['content']['body'] ?? ''), 'the words');
    assertTrue(!str_contains((string) ($answer['block']['content']['body'] ?? ''), 'x()'), 'an event attribute survived');
    assertTrue(!str_contains((string) ($answer['block']['content']['body'] ?? ''), '<script'), 'a script survived');
    assertTrue(!array_key_exists('id', $answer['block'] ?? []), 'who the block is came from the request');

    $refused = json_decode(dispatch("/admin/pages/{$id}/fields", null, 'POST', ['_csrf' => (new Session())->csrfToken(), 'blocks' => ['n4' => ['type' => 'hero', 'heading' => '']]])->body, true);
    assertTrue(isset($refused['errors']['heading']), 'a required heading left empty is named by its field: ' . json_encode($refused['errors'] ?? null));
});

testBothDrivers('Publish, Discard and a restore answer in JSON with the document to draw next', function (string $driver) {
    $db = adminSite($driver);
    [$id, ] = publishedTextPage($db);
    [$document] = builderBand($id);

    assertEquals(409, builderRequest("/admin/pages/{$id}/discard", [])->status, 'nothing to discard');

    $document['blocks'][0]['content']['body'] = '<p>Drafted</p>';
    assertEquals(200, draftRequest($id, ['version' => 0, 'document' => $document])->status, 'autosaved');
    assertContains('Live words', dispatch('/news')->body, 'a draft is not the page');
    $discarded = json_decode(builderRequest("/admin/pages/{$id}/discard", [])->body, true);
    assertEquals('published', $discarded['state'] ?? null, 'back to the published page');
    assertContains('Live words', json_encode($discarded['document'] ?? null) ?: '', 'and its document, to draw');

    assertEquals(200, draftRequest($id, ['version' => 0, 'document' => $document])->status, 'drafted again');
    $published = json_decode(builderRequest("/admin/pages/{$id}/publish", [])->body, true);
    assertEquals(true, $published['ok'] ?? null, 'published');
    assertEquals('published', $published['state'] ?? null, 'and says so');
    assertContains('Drafted', dispatch('/news')->body, 'the visitor sees it');

    $revision = (int) ($db->one('SELECT id FROM page_revisions WHERE page_id = ? ORDER BY id DESC', [$id])['id'] ?? 0);
    $restored = json_decode(builderRequest("/admin/pages/{$id}/restore", ['revision' => $revision, 'version' => (int) $published['version']])->body, true);
    assertEquals('changes', $restored['state'] ?? null, 'a restore is a draft over the published page');
    assertContains('Live words', json_encode($restored['document'] ?? null) ?: '', 'holding what the page said before');
    assertContains('Drafted', dispatch('/news')->body, 'and never the live page');

    $document['title'] = '';
    draftRequest($id, ['version' => (int) $restored['version'], 'document' => $document]);
    $refused = builderRequest("/admin/pages/{$id}/publish", []);
    assertEquals(422, $refused->status, 'a draft with no title is not published');
    assertTrue(isset(json_decode($refused->body, true)['errors']['title']), 'and the title is named');
});

testBothDrivers('the preview is the draft as a whole page, never indexed, and the live page is untouched', function (string $driver) {
    $db = adminSite($driver);
    [$id, ] = publishedTextPage($db);
    [$document] = builderBand($id);
    $document['blocks'][0]['content']['body'] = '<p>Previewed words</p>';
    draftRequest($id, ['version' => 0, 'document' => $document]);

    $preview = dispatch("/admin/pages/{$id}/preview")->body;
    assertContains('Previewed words', $preview, 'the draft');
    assertContains('<meta name="robots" content="noindex', $preview, 'never indexed');
    $live = dispatch('/news')->body;
    // The whole page as a visitor gets it: every stylesheet the published page links, and its
    // main. (The test site draws no header, so the header is not what is compared.)
    preg_match_all('~<link rel="stylesheet" href="([^"]+)"~', $live, $sheets);
    assertTrue(count($sheets[1]) > 2, 'the visitor\'s page links its stylesheets');
    foreach ($sheets[1] as $sheet) {
        assertContains('href="' . $sheet . '"', $preview, 'the preview links ' . $sheet . ', as a visitor gets it');
    }
    assertContains('<main', $preview, 'the page\'s main');
    assertContains('Live words', $live, 'the visitor still has the published page');
});

testBothDrivers('a band kept as a pattern is offered back, a copy in the page\'s language', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', true, [['type' => 'text', 'content' => ['body' => '<p>Kept words</p>'], 'style' => ['surface' => 'tinted']]]);
    [, $section, $blocks] = builderBand($id);

    $saved = json_decode(builderRequest('/admin/patterns', ['name' => 'Tinted words', 'section' => $section, 'blocks' => $blocks])->body, true);
    assertEquals(['Tinted words'], array_column(PagePattern::mine($db), 'name'), 'kept under its name');
    $pattern = json_decode(dispatch('/admin/patterns?ref=' . rawurlencode((string) $saved['ref']) . '&page=' . $id)->body, true)['pattern'] ?? fail('not offered back');
    assertEquals('tinted', $pattern['section']['style']['surface'] ?? null, 'its band\'s look');
    assertContains('Kept words', json_encode($pattern['blocks']) ?: '', 'its words');
    assertEquals(422, builderRequest('/admin/patterns', ['name' => '', 'section' => $section, 'blocks' => $blocks])->status, 'a pattern needs a name');
});

testBothDrivers('an empty band is drawn in the editor and never on the page', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', true, [['type' => 'text', 'content' => ['body' => '<p>Stored</p>']]]);
    [, $section] = builderBand($id);
    $section['key'] = 'm9';
    $section['id'] = null;
    $section['layout'] = 'halves';

    // To an author it is the band they just added and are about to fill.
    $drawn = json_decode(builderRequest("/admin/pages/{$id}/render", ['section' => $section, 'blocks' => []])->body, true)['html'] ?? '';
    assertContains('data-bx-section="m9"', $drawn, 'the editor hid the empty band');
    assertContains('cols-halves', $drawn, 'drawn without its shape');
    // To a visitor nothing of it exists: it was never written.
    assertEquals(1, substr_count(dispatch('/about')->body, '<section class="block'), 'the visitor was shown a band');
});
