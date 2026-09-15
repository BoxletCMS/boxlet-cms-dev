<?php

use App\Core\Response;

// The editor transport: one plain form that works without JavaScript and never saves a
// truncated submission.

/**
 * Just the editor form, where the real field groups are: not the logout form in the
 * admin header before it, nor the <template> copies for adding blocks after it.
 */
function editorForm(Response $response): string
{
    $start = strpos($response->body, 'data-page-editor>');
    $end = $start === false ? false : strpos($response->body, '</form>', $start);
    if ($start === false || $end === false) {
        fail('the response contains no page editor form');
    }

    return substr($response->body, $start, $end - $start);
}

test('the editor is a plain form: named inputs per block, _end last, no inline script', function () {
    $db = adminSite('sqlite');
    $id = createPage($db, 'en', 'about', 'About', false, [['type' => 'text', 'content' => ['body' => '<p>A</p>']]]);
    $response = dispatch("/admin/pages/{$id}");

    assertEquals(200, $response->status, 'status');
    assertContains('name="blocks[0][body]"', $response->body, 'editor');
    assertTrue((bool) preg_match('~name="_end" value="1">\s*</form>~', $response->body), '_end is not the last field of the form');
    assertContains('<template data-block-template="hero">', $response->body, 'block templates for adding');
    assertTrue(!preg_match('~<script(?![^>]*\bsrc=)~', $response->body), 'the editor contains an inline script');
});

test('without JavaScript, Add shows a new block and saves nothing', function () {
    $db = adminSite('sqlite');
    $id = createPage($db, 'en', 'about', 'About', false, [['type' => 'text', 'content' => ['body' => '<p>A</p>']]]);
    $blockId = (int) ($db->one('SELECT id FROM page_blocks')['id'] ?? 0);
    $blocks = [['id' => (string) $blockId, 'type' => 'text', 'heading' => 'Typed, not saved', 'body' => '<p>A</p>']];

    $response = adminPost("/admin/pages/{$id}", ['title' => 'About', 'slug' => 'about', 'blocks' => $blocks, 'add_type' => 'hero', 'action' => 'add', '_end' => '1']);
    assertEquals(200, $response->status, 'status');
    $form = editorForm($response);
    assertEquals(2, substr_count($form, 'data-block>'), 'blocks in the form');
    assertContains('name="blocks[1][type]" value="hero"', $form, 'the new hero block');
    assertContains('value="Typed, not saved"', $form, 'the typed value is kept in the form');
    assertEquals(['text'], blockTypes($db, $id), 'stored blocks');
    assertEquals('', storedContent($db, $blockId)['heading'] ?? null, 'stored heading');
});

test('without JavaScript, Move down swaps blocks in the form and saves nothing', function () {
    $db = adminSite('sqlite');
    $id = createPage($db, 'en', 'about', 'About', false, [
        ['type' => 'text', 'content' => ['body' => '<p>A</p>']],
        ['type' => 'hero', 'content' => ['heading' => 'H']],
    ]);
    [$text, $hero] = array_map('strval', array_column($db->all('SELECT id FROM page_blocks ORDER BY sort'), 'id'));
    $blocks = [['id' => $text, 'type' => 'text', 'body' => '<p>A</p>'], ['id' => $hero, 'type' => 'hero', 'heading' => 'H']];

    $response = adminPost("/admin/pages/{$id}", ['title' => 'About', 'slug' => 'about', 'blocks' => $blocks, 'action' => 'down-0', '_end' => '1']);
    assertEquals(200, $response->status, 'status');
    $form = editorForm($response);
    assertContains('name="blocks[0][type]" value="hero"', $form, 'hero moved to the top');
    assertContains('name="blocks[1][type]" value="text"', $form, 'text moved down');
    assertEquals(['text', 'hero'], blockTypes($db, $id), 'stored order');
});

test('a save that lost its last field to max_input_vars is refused, not truncated', function () {
    $db = adminSite('sqlite');
    $id = createPage($db, 'en', 'about', 'About', false, [['type' => 'text', 'content' => ['body' => '<p>Original</p>']]]);
    $blockId = (int) ($db->one('SELECT id FROM page_blocks')['id'] ?? 0);

    // What PHP hands over after cutting input off at the limit: the fields before it, no _end.
    $blocks = [['id' => (string) $blockId, 'type' => 'text', 'body' => '<p>Partial</p>']];
    $response = adminPost("/admin/pages/{$id}", ['title' => 'Truncated', 'slug' => 'about', 'blocks' => $blocks, 'action' => 'save']);

    assertEquals(422, $response->status, 'status');
    assertContains(e(t('pages.editor.truncated', ['limit' => (int) ini_get('max_input_vars')])), $response->body, 'message');
    assertEquals('About', $db->one('SELECT title FROM pages')['title'] ?? null, 'stored title');
    assertEquals('<p>Original</p>', storedContent($db, $blockId)['body'] ?? null, 'stored body');
});

test('a save with as many fields as max_input_vars is refused, even with _end present', function () {
    $db = adminSite('sqlite');
    $id = createPage($db, 'en', 'about', 'About', false);
    $limit = (int) ini_get('max_input_vars');
    $body = ['title' => 'Too big', 'slug' => 'about', 'padding' => array_fill(0, $limit, 'x'), 'action' => 'save', '_end' => '1'];

    assertEquals(422, adminPost("/admin/pages/{$id}", $body)->status, 'status');
    assertEquals('About', $db->one('SELECT title FROM pages')['title'] ?? null, 'stored title');
});
