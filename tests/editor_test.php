<?php

use App\Core\Response;

// The fallback editor's transport: one plain form that works without JavaScript and
// never saves a truncated submission. It lives at /admin/pages/{id}/form since Slice
// 4.6; the visual editor took the bare page URL (tests/builder_test.php). Both post to
// the same endpoint and are validated by the same code.

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
    $response = dispatch("/admin/pages/{$id}/form");

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
    assertEquals(2, substr_count($form, 'class="block-editor"'), 'blocks in the form');
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

// A media field offers a choice, never a number. Nobody can know that "7" is the harbour
// photograph, and without JavaScript this select IS the control — the picker only replaces
// it when scripts run. The server validates the same way either way (MediaReference).
test('without JavaScript, a media field is a list of pictures and never an id', function () {
    $db = adminSite('sqlite');
    $first = referenceMedia($db, 'hash-editor-one');
    $second = referenceMedia($db, 'hash-editor-two');
    $id = createPage($db, 'en', 'about', 'About', false, [
        ['type' => 'hero', 'content' => ['heading' => 'H', 'image' => $first]],
    ]);

    $form = editorForm(dispatch("/admin/pages/{$id}/form"));

    assertContains('name="blocks[0][image]"', $form, 'the media field');
    assertTrue(!preg_match('~<input[^>]*name="blocks\[0\]\[image\]"~', $form), 'the media field is still a bare input');
    assertContains(e(t('pages.field.media_none')), $form, 'the option for no picture');
    assertContains('<option value="' . $first . '" selected>', $form, 'the stored picture is not selected');
    // Newest first, asserted as an ORDER. Checking only that both options are present
    // would pass just as happily with the list reversed, which tests nothing about order.
    // Anchored on <option, never on a bare value="N": the block's own hidden id input
    // carries value="1" and sits BEFORE the select, so the bare search finds that instead
    // and the assertion measures the wrong occurrence. Measured — the hidden input at
    // offset 2034, the options at 2810 and 2867.
    $newest = strpos($form, '<option value="' . $second . '"');
    $oldest = strpos($form, '<option value="' . $first . '"');
    assertTrue($newest !== false && $oldest !== false && $newest < $oldest, 'pictures are not offered newest first');
    // The id itself never appears as something to type.
    assertTrue(!str_contains($form, 'type="number"'), 'a media id is still typed as a number');
});

// guard (source, not behaviour): this runner has no browser, so it stands over what the
// picker is BUILT from. The resting state has to carry a thumbnail, a name and a verb —
// the first version set the button's text to the bare filename and was mistaken for a text
// field, which is the regression this would catch if someone simplified it back.
test('guard (source, not behaviour): the picker\'s resting state is more than a filename', function () {
    $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/media-picker.js');

    foreach (['media-picker-thumb', 'media-picker-name', 'media-picker-verb', 'media-picker-empty'] as $part) {
        assertContains($part, $js, "the resting state no longer builds {$part}");
    }
    // The verb changes with the state; one label for both would say "Choose picture" over a
    // picture that is already chosen.
    assertContains("text(select, 'change')", $js, 'the verb no longer changes when a picture is chosen');
    assertContains("text(select, 'choose')", $js, 'the verb for an empty field is gone');

    // Both labels have to reach the browser, and the admin's CSP allows no inline script,
    // so they travel as data attributes on the field itself. They moved out of the block
    // template into MediaReference when site settings became a second screen with pickers.
    //
    // Deliberately stronger than the version that read the template: checking only where
    // the strings live would still pass if a template stopped calling for them, so every
    // template that renders a picker is named here too. A third one has to join this list.
    $attributes = (string) file_get_contents(dirname(__DIR__) . '/app/Modules/Media/MediaReference.php');
    assertContains('data-text-choose', $attributes, 'the choose label never reaches the picker');
    assertContains('data-text-change', $attributes, 'the change label never reaches the picker');

    foreach ([
        'app/Modules/Pages/views/admin/block.php',
        'app/Modules/Settings/views/settings.php',
    ] as $template) {
        assertContains(
            'pickerAttributes()',
            (string) file_get_contents(dirname(__DIR__) . '/' . $template),
            "{$template} renders a picker without the labels",
        );
    }

    // And the thumbnail comes from the server, never assembled in JavaScript: media URLs
    // use named presets only (SPEC §6).
    assertTrue(!str_contains($js, '/m/'), 'the picker builds a media URL itself');
});

test('choosing no picture stores null; a dangling id is nulled, a malformed one refused', function () {
    $db = adminSite('sqlite');
    $mediaId = referenceMedia($db, 'hash-editor-clear');
    $id = createPage($db, 'en', 'about', 'About', false, [
        ['type' => 'hero', 'content' => ['heading' => 'H', 'image' => $mediaId]],
    ]);
    $blockId = (int) ($db->one('SELECT id FROM page_blocks')['id'] ?? 0);

    // The empty option posts an empty string, which is what "no picture" means.
    $cleared = [['id' => (string) $blockId, 'type' => 'hero', 'heading' => 'H', 'image' => '']];
    $response = adminPost("/admin/pages/{$id}", ['title' => 'About', 'slug' => 'about', 'blocks' => $cleared, 'action' => 'save', '_end' => '1']);
    assertRedirectedTo("/admin/pages/{$id}", $response);
    // array_key_exists, never ??. The null coalescing operator reports a key whose value
    // IS null as missing, so `?? 'missing'` can never observe the null this asserts. That
    // has now cost a cycle here and in media_reference_test; the two cases are asserted
    // apart so a field that genuinely vanished fails with that as its reason.
    $stored = storedContent($db, $blockId);
    $stored = is_array($stored) ? $stored : [];
    assertTrue(array_key_exists('image', $stored), 'the media field left the stored content entirely');
    assertEquals(null, $stored['image'], 'clearing the picture');

    // A WELL-FORMED id naming no picture is not refused: it is nulled on save, the same
    // rule MediaReference applies everywhere. That is what makes a picture deleted between
    // opening the form and saving it harmless rather than an error the author cannot fix.
    $dangling = [['id' => (string) $blockId, 'type' => 'hero', 'heading' => 'H', 'image' => '4242']];
    $saved = adminPost("/admin/pages/{$id}", ['title' => 'About', 'slug' => 'about', 'blocks' => $dangling, 'action' => 'save', '_end' => '1']);
    assertRedirectedTo("/admin/pages/{$id}", $saved);
    $after = storedContent($db, $blockId);
    $after = is_array($after) ? $after : [];
    assertTrue(array_key_exists('image', $after), 'the media field left the stored content entirely');
    assertEquals(null, $after['image'], 'a dangling id was stored');

    // A value that is not a number at all was never a choice the form offered, so it is
    // refused rather than discarded: silently dropping it would hide a broken submission.
    $malformed = [['id' => (string) $blockId, 'type' => 'hero', 'heading' => 'H', 'image' => 'not-a-number']];
    $refused = adminPost("/admin/pages/{$id}", ['title' => 'About', 'slug' => 'about', 'blocks' => $malformed, 'action' => 'save', '_end' => '1']);
    assertEquals(422, $refused->status, 'status');
    assertContains(e(t('pages.field.media')), $refused->body, 'the refusal names what is wrong');
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
