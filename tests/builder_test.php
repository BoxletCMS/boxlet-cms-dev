<?php

// The visual editor: a canvas showing the real page, with the block's own fields beside
// it. Most of this screen is browser behaviour the runner cannot reach; what it can test
// is the HTML both halves are built from, and that the save path did not change.

/**
 * @return int the id of a page with three blocks of different types
 */
function builderPage(string $driver = 'sqlite'): int
{
    $db = adminSite($driver);

    return createPage($db, 'en', 'about', 'About', true, [
        ['type' => 'hero', 'content' => ['heading' => 'Welcome']],
        ['type' => 'text', 'content' => ['body' => '<p>Body copy</p>']],
        ['type' => 'image_text', 'content' => ['heading' => 'Beside', 'body' => '<p>More</p>']],
    ]);
}

test('the visual editor holds every block\'s fields in one form, with _end last', function () {
    $id = builderPage();
    $response = dispatch("/admin/pages/{$id}");

    assertEquals(200, $response->status, 'status');
    // Every block is in the form, not only the selected one: that is what keeps the
    // save path identical to the fallback editor's.
    foreach ([0, 1, 2] as $index) {
        assertContains('data-block-group="' . $index . '"', $response->body, "group {$index}");
    }
    // data-block-group above is still positional — it is which group the panel shows.
    // A field NAME is not: it carries the block's own key (D-094), so a reorder renames
    // nothing and an error keyed to a block stays with that block. Read out of the page
    // rather than looked up, which also proves the keys are the stored ids and in order.
    preg_match_all('~name="blocks\[(b[0-9]+)\]\[type\]" value="([a-z_]+)"~', $response->body, $named, PREG_SET_ORDER);
    assertEquals(['hero', 'text', 'image_text'], array_column($named, 2), 'every block, named by its own key, in order');
    assertContains('name="blocks[' . $named[0][1] . '][heading]"', $response->body, 'hero field');
    assertContains('name="blocks[' . $named[1][1] . '][body]"', $response->body, 'text field');
    assertTrue((bool) preg_match('~name="_end" value="1">\s*</form>~', $response->body), '_end is not the last field');
    assertContains('name="editor" value="builder"', $response->body, 'the editor marker');
    assertContains('data-canvas', $response->body, 'the canvas frame');

    // admin.js claims any form marked data-page-editor and then reaches for its block
    // list, which the visual editor does not have. Marking this form would throw on
    // every load, and nothing but a browser would notice.
    assertTrue(!str_contains($response->body, 'data-page-editor'), 'the builder form is marked as the fallback editor');
});

test('the visual editor links the fallback, and the fallback links back', function () {
    $id = builderPage();

    assertContains('href="/admin/pages/' . $id . '/form"', dispatch("/admin/pages/{$id}")->body, 'link to the fallback');
    assertContains('href="/admin/pages/' . $id . '"', dispatch("/admin/pages/{$id}/form")->body, 'link to the visual editor');
});

test('the canvas renders the real page, with sections as direct children of main', function () {
    $id = builderPage();
    $response = dispatch("/admin/pages/{$id}/canvas");

    assertEquals(200, $response->status, 'status');
    // sections.css styles a section by its position among its siblings, so anything
    // inserted between main and a section would change the page being judged.
    //
    // The band carries data-bx-section before its class since D-099 — the editor's one
    // addition to the visitor's markup, so the + in an empty column can say which band it
    // is aiming at. What this asserts is unchanged: the FIRST thing inside main is a
    // section, and it is the hero's.
    assertTrue(
        (bool) preg_match('~<main data-bx-blocks>\s*<section [^>]*class="block block-hero ~', $response->body),
        'the first section is not a direct child of main',
    );
    assertTrue(
        // Its KEY and not its id — `s8` for a stored band, `m0` for one made in this
        // session (D-098). The canvas has to name a band that may not be saved yet.
        (bool) preg_match('~<section data-bx-section="[sm][0-9]+" class="block block-hero ~', $response->body),
        'the band does not say which band it is, so an empty column could not be aimed at',
    );
    assertContains('Welcome', $response->body, 'block content');
    assertContains('<p>Body copy</p>', $response->body, 'rich text');
    assertTrue(!str_contains($response->body, 'admin-bar'), 'the canvas carries admin chrome');
});

test('the canvas is the one admin document that renders with the site design', function () {
    $id = builderPage();
    $canvas = dispatch("/admin/pages/{$id}/canvas");
    $shell = dispatch("/admin/pages/{$id}");

    // The canvas is the site, so it links the site's compiled tokens and stylesheets.
    assertTrue((bool) preg_match('~/cache/tokens\.[0-9a-f]{12}\.css~', $canvas->body), 'canvas tokens');
    assertContains('assets/site.css', $canvas->body, 'canvas site styles');
    assertContains('assets/canvas.css', $canvas->body, 'canvas editor chrome');

    // The shell around it is the admin, so it links none of them.
    assertTrue(!str_contains($shell->body, '/cache/tokens.'), 'the shell links the site design');
    assertContains('assets/admin.css', $shell->body, 'shell admin styles');
});

test('the canvas may be framed by the admin and by nobody else', function () {
    $id = builderPage();
    $canvas = dispatch("/admin/pages/{$id}/canvas");

    assertContains("frame-ancestors 'self'", $canvas->headers['Content-Security-Policy'] ?? '', 'CSP');
    assertEquals('SAMEORIGIN', $canvas->headers['X-Frame-Options'] ?? null, 'X-Frame-Options');
});

test('the visual editor and the canvas require an admin session, and refuse a missing page', function () {
    $id = builderPage();
    $_SESSION = [];

    foreach (["/admin/pages/{$id}", "/admin/pages/{$id}/canvas", "/admin/pages/{$id}/form"] as $path) {
        assertEquals('/admin/login', dispatch($path)->headers['Location'] ?? null, $path);
    }

    adminSite('sqlite');
    assertEquals(404, dispatch('/admin/pages/9999/canvas')->status, 'canvas of a page that does not exist');
});

testBothDrivers('a save from the visual editor stores exactly what the plain form would', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', false, [['type' => 'text', 'content' => ['body' => '<p>Before</p>']]]);
    $blockId = (int) ($db->one('SELECT id FROM page_blocks')['id'] ?? 0);

    $response = adminPost("/admin/pages/{$id}", [
        'title' => 'Edited in the canvas',
        'slug' => 'about',
        'editor' => 'builder',
        'blocks' => [['id' => (string) $blockId, 'type' => 'text', 'heading' => 'Now', 'body' => '<p>After</p>']],
        'action' => 'save',
        '_end' => '1',
    ]);

    assertRedirectedTo("/admin/pages/{$id}", $response);
    assertEquals('Edited in the canvas', $db->one('SELECT title FROM pages')['title'] ?? null, 'stored title');
    assertEquals('<p>After</p>', storedContent($db, $blockId)['body'] ?? null, 'stored body');
});

// An empty slug means "the home page of this language". A save that leaves the address
// out would therefore either be refused, or quietly move the page to the site's root.
testBothDrivers('saving from the visual editor keeps the page\'s address', function (string $driver) {
    $db = adminSite($driver);
    createPage($db, 'en', '', 'Home', true);
    $id = createPage($db, 'en', 'about', 'About', true, [['type' => 'text', 'content' => ['body' => '<p>Kept</p>']]]);
    $blockId = (string) ($db->one('SELECT id FROM page_blocks WHERE page_id = ?', [$id])['id'] ?? '');

    assertContains('name="slug" value="about"', dispatch("/admin/pages/{$id}")->body, 'the address is not carried');

    $response = adminPost("/admin/pages/{$id}", [
        'title' => 'About',
        'slug' => 'about',
        'editor' => 'builder',
        'blocks' => [['id' => $blockId, 'type' => 'text', 'heading' => '', 'body' => '<p>Kept</p>']],
        'action' => 'save',
        '_end' => '1',
    ]);

    assertRedirectedTo("/admin/pages/{$id}", $response);
    assertEquals('about', $db->one('SELECT slug FROM pages WHERE id = ?', [$id])['slug'] ?? null, 'stored address');
});

test('an error with no field on screen is still shown', function () {
    $db = adminSite('sqlite');
    createPage($db, 'en', '', 'Home', true);
    $id = createPage($db, 'en', 'about', 'About', true, [['type' => 'text', 'content' => ['body' => '<p>Kept</p>']]]);
    $blockId = (string) ($db->one('SELECT id FROM page_blocks WHERE page_id = ?', [$id])['id'] ?? '');

    // An empty address collides with the existing home page. The visual editor has no
    // address field, so without this the user is told to fix something nowhere on screen.
    $response = adminPost("/admin/pages/{$id}", [
        'title' => 'About',
        'slug' => '',
        'editor' => 'builder',
        'blocks' => [['id' => $blockId, 'type' => 'text', 'heading' => '', 'body' => '<p>Kept</p>']],
        'action' => 'save',
        '_end' => '1',
    ]);

    assertEquals(422, $response->status, 'status');
    assertContains(e(t('pages.slug.home_taken')), $response->body, 'the reason is on screen');
});

// The page panel: title, address, parent and visibility travel with the save.

testBothDrivers('the page panel saves the parent and the visibility', function (string $driver) {
    $db = adminSite($driver);
    $parent = createPage($db, 'en', 'about', 'About', true);
    $id = createPage($db, 'en', 'team', 'Team', false, [['type' => 'text', 'content' => ['body' => '<p>x</p>']]]);
    $blockId = (string) ($db->one('SELECT id FROM page_blocks WHERE page_id = ?', [$id])['id'] ?? '');

    $response = adminPost("/admin/pages/{$id}", [
        'title' => 'Team',
        'slug' => 'team',
        'editor' => 'builder',
        'parent_id' => (string) $parent,
        'status' => 'published',
        'blocks' => [['id' => $blockId, 'type' => 'text', 'heading' => '', 'body' => '<p>x</p>']],
        'action' => 'save',
        '_end' => '1',
    ]);

    assertRedirectedTo("/admin/pages/{$id}", $response);
    $row = $db->one('SELECT parent_id, status, published_at FROM pages WHERE id = ?', [$id]) ?? [];
    assertEquals($parent, (int) ($row['parent_id'] ?? 0), 'stored parent');
    assertEquals('published', $row['status'] ?? null, 'stored visibility');
    assertTrue(($row['published_at'] ?? null) !== null, 'published_at was not stamped');
});

// The select never offers a parent that would make a cycle. This is the request that
// does not come from the select.
test('a parent that would make a cycle is refused however the request arrives', function () {
    $db = adminSite('sqlite');
    $about = createPage($db, 'en', 'about', 'About', true, [['type' => 'text', 'content' => ['body' => '<p>x</p>']]]);
    $team = createPage($db, 'en', 'team', 'Team', true);
    $db->query('UPDATE pages SET parent_id = ? WHERE id = ?', [$about, $team]);
    $blockId = (string) ($db->one('SELECT id FROM page_blocks WHERE page_id = ?', [$about])['id'] ?? '');

    $response = adminPost("/admin/pages/{$about}", [
        'title' => 'About',
        'slug' => 'about',
        'editor' => 'builder',
        'parent_id' => (string) $team,
        'status' => 'published',
        'blocks' => [['id' => $blockId, 'type' => 'text', 'heading' => '', 'body' => '<p>x</p>']],
        'action' => 'save',
        '_end' => '1',
    ]);

    assertEquals(422, $response->status, 'status');
    assertContains(e(t('pages.parent_invalid')), $response->body, 'the reason is on screen');
    assertEquals(null, $db->one('SELECT parent_id FROM pages WHERE id = ?', [$about])['parent_id'] ?? null, 'stored parent');
});

test('the page panel carries the address as a real field, not a hidden one', function () {
    $db = adminSite('sqlite');
    createPage($db, 'en', '', 'Home', true);
    $id = createPage($db, 'en', 'about', 'About', true);
    $body = dispatch("/admin/pages/{$id}")->body;

    assertContains('name="slug" value="about"', $body, 'the address');
    assertContains('data-slug-field', $body, 'the generator hook');
    assertContains('name="parent_id"', $body, 'the parent select');
    assertContains('name="status"', $body, 'the visibility select');
    // Home is a valid parent for About; About must not be offered itself. Read inside the
    // parent select only: a link field elsewhere on the screen rightly offers every page,
    // About included, and the whole body stopped proving anything once the Columns block
    // put link fields into the repeater templates every builder carries.
    $parent = preg_match('~<select id="page-parent" name="parent_id">(.*?)</select>~s', $body, $match) === 1 ? $match[1] : '';
    assertContains('>Home</option>', $parent, 'another page as a parent');
    assertTrue(!str_contains($parent, '>About</option>'), 'the page was offered itself as its parent');
});

// A rejected save re-renders the builder, and the canvas reloads. It reads the database,
// which is precisely what was not written, so without care the page appears to empty
// itself while every field is still full.
test('a rejected save leaves the canvas showing the work, not the stored page', function () {
    $db = adminSite('sqlite');
    createPage($db, 'en', '', 'Home', true);
    $id = createPage($db, 'en', 'about', 'About', true, [['type' => 'text', 'content' => ['body' => '<p>Stored</p>']]]);
    $blockId = (string) ($db->one('SELECT id FROM page_blocks WHERE page_id = ?', [$id])['id'] ?? '');

    $rejected = adminPost("/admin/pages/{$id}", [
        'title' => '',
        'slug' => 'about',
        'editor' => 'builder',
        'blocks' => [['id' => $blockId, 'type' => 'text', 'heading' => '', 'body' => '<p>Being written</p>']],
        'action' => 'save',
        '_end' => '1',
    ]);
    assertEquals(422, $rejected->status, 'status');

    $canvas = dispatch("/admin/pages/{$id}/canvas");
    assertContains('<p>Being written</p>', $canvas->body, 'the canvas lost the unsaved work');
    assertTrue(!str_contains($canvas->body, '<p>Stored</p>'), 'the canvas showed the stored page instead');

    // Read once: the next canvas is the stored page again.
    assertContains('<p>Stored</p>', dispatch("/admin/pages/{$id}/canvas")->body, 'the pending state was never cleared');
});

test('a rejected save comes back in the editor it was sent from', function () {
    $db = adminSite('sqlite');
    $id = createPage($db, 'en', 'about', 'About', false, [['type' => 'text', 'content' => ['body' => '<p>Kept</p>']]]);
    $blockId = (string) ($db->one('SELECT id FROM page_blocks')['id'] ?? '');
    $blocks = [['id' => $blockId, 'type' => 'text', 'body' => '']];
    $body = ['title' => '', 'slug' => 'about', 'blocks' => $blocks, 'action' => 'save', '_end' => '1'];

    $fromBuilder = adminPost("/admin/pages/{$id}", $body + ['editor' => 'builder']);
    assertEquals(422, $fromBuilder->status, 'status');
    assertContains('data-canvas', $fromBuilder->body, 'the builder came back');
    assertContains(e(t('pages.title_required')), $fromBuilder->body, 'the error');

    $fromForm = adminPost("/admin/pages/{$id}", $body);
    assertEquals(422, $fromForm->status, 'status');
    assertContains('<template data-block-template=', $fromForm->body, 'the plain form came back');
    assertEquals('<p>Kept</p>', storedContent($db, (int) $blockId)['body'] ?? null, 'nothing was stored');
});

/*
 * THE TRAP UNDER D-081, held open by a test because reasoning alone found it late.
 *
 * builder-save.js lets a block the author never touched send its id and a marker instead
 * of its fields, and the server restores it from storage. That is only true while what is
 * on screen CAME from storage. A save that fails validation re-renders the submitted
 * blocks — valid edits included, because a save is refused whole — so a baseline taken
 * there would call an edited block unchanged and roll it back on the next save.
 *
 * The signal is one attribute, and the default is the safe answer.
 */
test('the builder offers the skeleton saving only while the form is the stored page', function () {
    $db = adminSite('sqlite');
    $id = createPage($db, 'en', 'about', 'About', false, [['type' => 'text', 'content' => ['heading' => 'H', 'body' => '<p>A</p>']]]);
    $blockId = (int) ($db->one('SELECT id FROM page_blocks')['id'] ?? 0);

    $opened = dispatch("/admin/pages/{$id}");
    assertContains('data-blocks-stored', $opened->body, 'the editor opened on the stored page');

    // A save refused for an empty title comes back with the blocks as submitted.
    $rejected = adminPost("/admin/pages/{$id}", [
        'title' => '',
        'slug' => 'about',
        'editor' => 'builder',
        'blocks' => [['id' => (string) $blockId, 'type' => 'text', 'heading' => 'Edited, never saved', 'body' => '<p>A</p>']],
        'action' => 'save',
        '_end' => '1',
    ]);
    assertEquals(422, $rejected->status, 'status');
    assertContains('Edited, never saved', $rejected->body, 'the submitted heading is still in the form');
    assertTrue(!str_contains($rejected->body, 'data-blocks-stored'), 'a rejected save must not offer the skeleton saving');
});
