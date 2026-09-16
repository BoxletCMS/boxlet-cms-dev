<?php

use App\Core\Blocks;
use App\Modules\Pages\BlockPreview;

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
    assertContains('name="blocks[0][heading]"', $response->body, 'hero field');
    assertContains('name="blocks[1][body]"', $response->body, 'text field');
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
    assertTrue(
        (bool) preg_match('~<main data-bx-blocks>\s*<section class="block block-hero ~', $response->body),
        'the first section is not a direct child of main',
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

// Adding a block: the server renders both halves of it and nothing is stored until Save,
// exactly as in the fallback editor.

test('the insert endpoint returns a section and a field group for every block type', function () {
    $db = adminSite('sqlite');
    $id = createPage($db, 'en', 'about', 'About', false, [['type' => 'text', 'content' => ['body' => '<p>Only</p>']]]);
    $registry = Blocks::discover(dirname(__DIR__) . '/app/Blocks');

    foreach ($registry->types() as $type) {
        $response = adminPost("/admin/pages/{$id}/block", ['type' => $type, 'index' => '0']);

        assertEquals(200, $response->status, $type);
        assertContains('<template data-block-canvas>', $response->body, "{$type}: the canvas fragment");
        assertContains('<template data-block-fields>', $response->body, "{$type}: the field fragment");
        assertContains('class="block block-' . $type . ' ', $response->body, "{$type}: the rendered section");
        assertContains('name="blocks[0][type]" value="' . $type . '"', $response->body, "{$type}: the field group");
    }

    // Asking for a block is not adding one: only Save writes.
    assertEquals(['text'], blockTypes($db, $id), 'the insert endpoint stored a block');
});

test('the insert endpoint refuses a block type that is not installed', function () {
    $id = builderPage();
    $response = adminPost("/admin/pages/{$id}/block", ['type' => 'trojan_horse', 'index' => '0']);

    assertEquals(422, $response->status, 'status');
    assertContains(t('pages.insert_unknown'), $response->body, 'message');
});

testBothDrivers('a block added in the middle is stored in that position', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', false, [
        ['type' => 'hero', 'content' => ['heading' => 'One']],
        ['type' => 'text', 'content' => ['body' => '<p>Three</p>']],
    ]);
    [$hero, $text] = array_map('strval', array_column($db->all('SELECT id FROM page_blocks ORDER BY sort'), 'id'));

    // What the browser sends after inserting between them: the new block has no id.
    $response = adminPost("/admin/pages/{$id}", [
        'title' => 'About',
        'slug' => 'about',
        'editor' => 'builder',
        'blocks' => [
            ['id' => $hero, 'type' => 'hero', 'heading' => 'One', 'subheading' => '', 'image' => '', 'cta' => ['label' => '', 'url' => '']],
            ['type' => 'image_text', 'heading' => 'Two', 'body' => '<p>Two</p>', 'image' => '', 'image_fit' => 'cover', 'link' => ['label' => '', 'url' => '']],
            ['id' => $text, 'type' => 'text', 'heading' => '', 'body' => '<p>Three</p>'],
        ],
        'action' => 'save',
        '_end' => '1',
    ]);

    assertRedirectedTo("/admin/pages/{$id}", $response);
    assertEquals(['hero', 'image_text', 'text'], blockTypes($db, $id), 'stored order');
});

test('the library shows every block with a picture of itself', function () {
    $id = builderPage();
    $body = dispatch("/admin/pages/{$id}")->body;

    foreach (Blocks::discover(dirname(__DIR__) . '/app/Blocks')->types() as $type) {
        assertContains('data-add-type="' . $type . '"', $body, "{$type} is missing from the library");
    }
    assertTrue((bool) preg_match('~/cache/previews/hero\.[0-9a-f]{12}\.html~', $body), 'the hero preview is not linked');
});

// A hand-drawn thumbnail stops matching its block the first time the block changes and
// nobody notices. These are rendered from the block and from the site's own design.
test('every block has a preview, regenerated when the design changes and not otherwise', function () {
    $registry = Blocks::discover(dirname(__DIR__) . '/app/Blocks');
    $dir = tmpPath('previewcache');
    removeTree($dir);

    $first = BlockPreview::all($registry, 'tokens.aaaaaaaaaaaa.css', $dir);
    assertEquals($registry->types(), array_keys($first), 'a preview for every block');

    foreach ($first as $type => $file) {
        assertTrue((bool) preg_match('~^' . $type . '\.[0-9a-f]{12}\.html$~', $file), "file name {$file}");
        $html = (string) file_get_contents($dir . '/previews/' . $file);
        assertContains('class="block block-' . $type . ' ', $html, "{$type}: the block itself is rendered");
        assertContains('assets/site.css', $html, "{$type}: the site's stylesheet");
    }

    assertEquals($first, BlockPreview::all($registry, 'tokens.aaaaaaaaaaaa.css', $dir), 'regenerated for no reason');

    $second = BlockPreview::all($registry, 'tokens.bbbbbbbbbbbb.css', $dir);
    foreach ($second as $type => $file) {
        assertTrue($file !== $first[$type], "{$type} kept its preview after the design changed");
        assertTrue(!is_file($dir . '/previews/' . $first[$type]), "{$type}'s old preview was left behind");
    }
    removeTree($dir);
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
