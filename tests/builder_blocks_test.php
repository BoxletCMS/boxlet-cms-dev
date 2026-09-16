<?php

use App\Core\Blocks;
use App\Modules\Pages\BlockPreview;

// Changing a page in the visual editor: the library, adding a block, and re-drawing one
// as it is edited. The screen itself is tests/builder_test.php.
//
// The server renders both halves of a block — the section for the canvas and the field
// group for the form — so nothing here has to know what fields a block has, and neither
// does the browser. builderPage() lives in builder_test.php; the runner loads every file
// before it runs anything.

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

// Editing a block re-draws it on the canvas. The same endpoint answers, so the drawing
// goes through the same cleaning a save would.
test('the endpoint re-draws a block from the values being edited', function () {
    $id = builderPage();
    $response = adminPost("/admin/pages/{$id}/block", [
        'type' => 'text',
        'index' => '0',
        'block' => [
            'heading' => 'Heading being typed',
            'body' => '<p onclick="steal()">Body<script>bad()</script></p>',
        ],
    ]);

    assertEquals(200, $response->status, 'status');
    assertContains('Heading being typed', $response->body, 'the heading being typed');
    assertContains('<p>Body</p>', $response->body, 'rich text reduced to the whitelist');
    assertTrue(!str_contains($response->body, 'onclick'), 'an attribute survived into the canvas');
    assertTrue(!str_contains($response->body, 'bad()'), 'a script survived into the canvas');
});

test('the panel carries the editor\'s own block controls', function () {
    $body = dispatch('/admin/pages/' . builderPage())->body;

    foreach (['up', 'down', 'duplicate', 'remove'] as $action) {
        assertContains('data-block-action="' . $action . '"', $body, "the {$action} control");
    }
});
