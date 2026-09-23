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
        // The endpoint names a new block n0 and the BROWSER renames it the moment it places
        // it, because only the browser knows which keys the page already uses (D-094).
        assertContains('name="blocks[n0][type]" value="' . $type . '"', $response->body, "{$type}: the field group");
    }

    // Asking for a block is not adding one: only Save writes.
    assertEquals(['text'], blockTypes($db, $id), 'the insert endpoint stored a block');
});

// The canvas redraws by posting the block being edited to this endpoint and swapping the
// section it returns (PLAN.md 2h). Three things have to hold for that to be safe: it
// renders what the front end renders, it writes nothing, and it is not open to a request
// from elsewhere.

testBothDrivers('the endpoint renders exactly what the front end renders', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', true, [
        ['type' => 'text', 'content' => ['body' => '<p>Stored</p>']],
    ]);

    // The same fields, through the endpoint the canvas uses...
    $response = adminPost("/admin/pages/{$id}/block", [
        'type' => 'text',
        'index' => '0',
        'block' => ['type' => 'text', 'body' => '<p>Stored</p>'],
    ]);
    if (!preg_match('~<template data-block-canvas>(.*?)</template>~s', $response->body, $drawn)) {
        fail('the endpoint returned no section');
    }

    // ...and the same fields as the visitor sees them.
    $front = dispatch('/about')->body;
    if (!preg_match('~<section class="block block-text.*?</section>~s', $front, $rendered)) {
        fail('the front end rendered no section');
    }

    $normalise = static fn (string $html): string => trim((string) preg_replace(
        ['~\s+~', '~ (data-bx-[a-z]+|tabindex|role)="[^"]*"~'],
        [' ', ''],
        $html,
    ));

    assertEquals($normalise($rendered[0]), $normalise($drawn[1]), 'the canvas and the front end disagree');
});

testBothDrivers('a redraw draws the block in its BAND\'s style, not in the character\'s', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', true, [
        ['type' => 'text', 'content' => ['body' => '<p>Stored</p>'], 'style' => ['surface' => 'contrast', 'rhythm' => 'airy']],
    ]);

    // WHAT THE EDITOR SENDS WHILE SOMEBODY TYPES (D-099): the block's fields, and the
    // fields of the band it stands in. Without the second, this drew the block with the
    // character's composition and a keystroke took the band's look off the canvas — which
    // is what the owner saw, and what nothing here was asking about.
    $response = adminPost("/admin/pages/{$id}/block", [
        'type' => 'text',
        'index' => '0',
        'block' => ['type' => 'text', 'body' => '<p>Stored</p>'],
        'section' => ['style' => ['surface' => 'contrast', 'rhythm' => 'airy', 'width' => 'normal', 'align' => 'left', 'divider' => 'none']],
    ]);
    if (!preg_match('~<template data-block-canvas>(.*?)</template>~s', $response->body, $drawn)) {
        fail('the endpoint returned no section');
    }
    assertContains('surface-contrast', $drawn[1], 'the band\'s surface');
    assertContains('rhythm-airy', $drawn[1], 'the band\'s rhythm');

    // And it is the same thing the visitor is looking at, which is the whole promise of a
    // live canvas: it shows the page, not a guess at it.
    $front = dispatch('/about')->body;
    assertContains('surface-contrast', $front, 'the visitor sees the band\'s surface');
});

testBothDrivers('the endpoint writes nothing, whatever it is sent', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', false, [
        ['type' => 'text', 'content' => ['body' => '<p>Only</p>']],
    ]);
    $before = $db->all('SELECT id, block_type, content_json, style_json, layout, sort FROM page_blocks ORDER BY id');
    $pageBefore = $db->one('SELECT * FROM pages WHERE id = ?', [$id]);

    // A redraw of the stored block, a redraw with different text, and an added type.
    foreach ([
        ['type' => 'text', 'index' => '0', 'block' => ['type' => 'text', 'body' => '<p>Only</p>']],
        ['type' => 'text', 'index' => '0', 'block' => ['type' => 'text', 'body' => '<p>Changed while typing</p>']],
        ['type' => 'hero', 'index' => '1', 'block' => ['type' => 'hero', 'heading' => 'Typed']],
    ] as $body) {
        assertEquals(200, adminPost("/admin/pages/{$id}/block", $body)->status, 'status');
    }

    assertEquals($before, $db->all('SELECT id, block_type, content_json, style_json, layout, sort FROM page_blocks ORDER BY id'), 'the blocks changed');
    assertEquals($pageBefore, $db->one('SELECT * FROM pages WHERE id = ?', [$id]), 'the page row changed');
});

test('the endpoint refuses a request without a CSRF token', function () {
    $id = builderPage();

    // adminPost() adds the token; this is the same request without one.
    $response = dispatch("/admin/pages/{$id}/block", null, 'POST', ['type' => 'text', 'index' => '0']);

    assertEquals(403, $response->status, 'a request with no CSRF token was answered');
    assertTrue(!str_contains($response->body, '<template data-block-canvas>'), 'it rendered a block anyway');
});

testBothDrivers('the endpoint cleans what it is sent, the same way a save does', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', false, [['type' => 'text', 'content' => ['body' => '<p>Only</p>']]]);

    $response = adminPost("/admin/pages/{$id}/block", [
        'type' => 'text',
        'index' => '0',
        'block' => [
            'type' => 'text',
            'body' => '<p onclick="alert(1)">Kept<script>alert(2)</script></p>',
            'style' => ['surface' => 'not-a-surface'],
        ],
    ]);

    assertEquals(200, $response->status, 'status');
    assertContains('Kept', $response->body, 'the text survived');
    assertTrue(!str_contains($response->body, 'alert(1)'), 'an event attribute reached the canvas');
    assertTrue(!str_contains($response->body, 'alert(2)'), 'a script reached the canvas');
    // An invalid section style falls back to the default rather than being drawn.
    assertTrue(!str_contains($response->body, 'surface-not-a-surface'), 'an invalid surface was drawn');
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
    [$hero, $text] = array_map('strval', blockIdsInOrder($db));

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

// A preview is a picture of the BLOCK. Sampling a media field as id 1 made it a picture of
// whichever photograph happened to hold that number — nothing on a fresh install, and on a
// used one somebody's holiday snap appearing in the block library.
test('no preview claims a picture, and the ones that reserve a place still show it', function () {
    $registry = Blocks::discover(dirname(__DIR__) . '/app/Blocks');
    $dir = tmpPath('previewclaims');
    removeTree($dir);

    $claimed = [];
    $placeholders = [];
    foreach (BlockPreview::all($registry, 'tokens.cccccccccccc.css', $dir) as $type => $file) {
        $html = (string) file_get_contents($dir . '/previews/' . $file);
        if (str_contains($html, 'data-media-id')) {
            $claimed[] = $type;
        }
        if (str_contains($html, 'media-placeholder')) {
            $placeholders[] = $type;
        }
    }

    assertEquals([], $claimed, 'a preview names a media id, so it depends on whatever holds that number');

    // Not every block: image_text draws its placeholder unconditionally, while hero draws
    // one only in a layout that reserves a picture area and its default layout is centred,
    // which reserves none. So this asserts the one that must, rather than all of them.
    assertTrue(in_array('image_text', $placeholders, true),
        'image_text lost the placeholder that shows where its picture goes');

    removeTree($dir);
});

// guard (source, not behaviour): the sample cannot be varied from a test, so this stands
// over the mechanism instead. The file name is hashed from what the preview is RENDERED
// from. It covered only the definition and the stylesheet, so changing how a field is
// sampled left every already-generated file in place and the correction never reached an
// install that already had previews.
test('guard (source, not behaviour): a preview\'s name follows its sample', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/app/Modules/Pages/BlockPreview.php');

    assertTrue(
        (bool) preg_match('~hash\(\s*.sha256.,\s*json_encode\(\s*\[\s*\$definition,\s*self::sample\(~', $source),
        'the preview file name no longer depends on the sample, so changing it leaves stale files',
    );
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

// THE RULE CHANGED (D-040): a block's controls were a row of buttons in the panel; the
// owner moved them onto the selected block, as icons in the canvas. The canvas draws them
// with a script, so what can be asserted here is what it draws them from — their words,
// handed over by the canvas page — and that the builder routes each one to the same act()
// the panel's buttons called. The panel no longer carries them.
test('the selected block\'s controls are on the canvas, and the builder acts on them', function () {
    $id = builderPage();
    $canvas = dispatch('/admin/pages/' . $id . '/canvas')->body;
    foreach (['pages.move_up', 'pages.move_down', 'pages.duplicate', 'pages.remove'] as $key) {
        assertContains(e(t($key)), $canvas, "the canvas does not carry the words for {$key}");
    }
    assertContains('data-icons=', $canvas, 'the canvas does not know where the icons are');

    $script = (string) file_get_contents(dirname(__DIR__) . '/public/assets/canvas.js');
    foreach (['up', 'down', 'duplicate', 'remove'] as $action) {
        assertContains("['{$action}',", $script, "canvas.js offers no {$action} control");
    }
    $builder = (string) file_get_contents(dirname(__DIR__) . '/public/assets/builder.js');
    assertContains("event.data.type === 'action' && api.act", $builder, 'builder.js does not route a canvas action');
    assertContains('api.act = act;', (string) file_get_contents(dirname(__DIR__) . '/public/assets/builder-blocks.js'), 'act() is not exposed');

    assertTrue(!str_contains(dispatch('/admin/pages/' . $id)->body, 'data-block-action="up"'), 'the panel still carries the controls');
});

testBothDrivers('the band endpoint draws a whole section and writes nothing', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', true, [
        ['type' => 'text', 'content' => ['body' => '<p>Left</p>']],
    ]);
    $before = blocksWithStyle($db, $id);

    // WHAT THE PANEL SENDS WHEN THE NUMBER OF COLUMNS CHANGES (D-099): the band's own
    // fields, and the fields of every block standing in it. The number of columns is the
    // markup AROUND the blocks, so no redraw of one of them can put a column there.
    $response = adminPost("/admin/pages/{$id}/section", [
        'section' => [
            'layout' => 'halves',
            'stack' => 'reverse',
            'style' => ['surface' => 'tinted', 'rhythm' => 'airy', 'width' => 'normal', 'align' => 'left', 'divider' => 'none'],
        ],
        'blocks' => ['b1' => ['type' => 'text', 'body' => '<p>Left</p>', 'column' => '0']],
    ]);

    assertEquals(200, $response->status, 'status');
    if (!preg_match('~<template data-band-canvas>(.*?)</template>~s', $response->body, $drawn)) {
        fail('the endpoint returned no band');
    }
    assertContains('section-cols cols-halves stack-reverse', $drawn[1], 'the columns it asked for');
    assertContains('surface-tinted', $drawn[1], 'the band\'s surface');
    // Two columns, one of them empty and waiting — the editor draws its + over that box.
    assertEquals(2, substr_count($drawn[1], '<div class="section-column">'), 'columns drawn');

    // It writes nothing, like its neighbour: the band exists only in the page being edited.
    assertEquals($before, blocksWithStyle($db, $id), 'the endpoint wrote to the page');
    $bands = App\Modules\Pages\Sections::forPage($db, $id);
    assertEquals('one', (reset($bands) ?: fail('no band'))['layout'], 'the stored band was changed');
});
