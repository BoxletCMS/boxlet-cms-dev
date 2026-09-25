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
        // The block ALONE since D-103: the band around it is the canvas's, not this
        // endpoint's, because a block always lands in a column.
        assertContains('class="block-' . $type . ' ', $response->body, "{$type}: the rendered block");
        assertTrue(!str_contains($response->body, '<section'), "{$type}: the endpoint wrapped it in a band");
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

testBothDrivers('the endpoint renders exactly what the canvas renders', function (string $driver) {
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
        fail('the endpoint returned no block');
    }

    /* ...and the same block as the CANVAS draws it.
     *
     * Against the canvas and no longer against the front end (D-103): the editor's canvas
     * always draws the column shape, because a column is what a block is dragged into, and
     * the page keeps the shape it has always had, because there it can be PROVEN nothing
     * moved. The promise this check exists for is unchanged — a block arriving from the
     * endpoint is the same markup as the block already on the screen beside it — and the
     * canvas is where that comparison is now meaningful. That the canvas and the page agree
     * is what SectionRender's two shapes and D-093's measurement are for. */
    $canvas = dispatch("/admin/pages/{$id}/canvas")->body;

    $normalise = static fn (string $html): string => trim((string) preg_replace(
        ['~\s+~', '~ (data-bx-[a-z]+|tabindex|role)="[^"]*"~'],
        [' ', ''],
        $html,
    ));

    /* CONTAINED, not equal to a slice cut out of the canvas. Cutting one block out of a
       document with a regular expression means counting closing tags, and a pattern that
       stops one short says the two disagree when they do not — which is what the first
       attempt at this did. What is actually promised is that the markup the endpoint hands
       the editor is, character for character, the markup the canvas already holds. */
    assertContains($normalise($drawn[1]), $normalise($canvas), 'the endpoint and the canvas disagree');
});

testBothDrivers('a redraw returns the block alone, and never the band around it', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', true, [
        ['type' => 'text', 'content' => ['body' => '<p>Stored</p>'], 'style' => ['surface' => 'contrast', 'rhythm' => 'airy']],
    ]);

    /* WHAT THE EDITOR SENDS WHILE SOMEBODY TYPES: the block's fields, and nothing else.
     *
     * For one day it sent the band's too (D-099), because a redraw then drew the block as a
     * whole band and drew it with a composed style when none arrived — one keystroke took a
     * tinted, airy, wide band to plain, normal, narrow on the canvas, which is what the
     * owner saw. Since D-103 the editor's canvas always draws columns and a redraw replaces
     * the BLOCK alone: the band's classes are on the band, which is never touched, so there
     * is nothing here for a style to change. That is what this asserts. */
    $response = adminPost("/admin/pages/{$id}/block", [
        'type' => 'text',
        'index' => '0',
        'block' => ['type' => 'text', 'body' => '<p>Stored</p>'],
    ]);
    if (preg_match('~<template data-block-canvas>(.*?)</template>~s', $response->body, $drawn) !== 1) {
        fail('the endpoint returned no block');
    }
    assertContains('block-text', $drawn[1], 'the block itself');
    assertTrue(!str_contains($drawn[1], '<section'), 'the endpoint wrapped the block in a band');
    foreach (['surface-', 'rhythm-', 'width-', 'align-', 'divider-'] as $ofTheBand) {
        assertTrue(!str_contains($drawn[1], $ofTheBand), "the block carries the band's {$ofTheBand} class");
    }

    // And the band on the page goes on carrying it, which is why a redraw cannot lose it.
    assertContains('surface-contrast', dispatch('/about')->body, 'the visitor sees the band\'s surface');
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

    // The tool bar is canvas-tools.js since the split of D-117.
    $script = (string) file_get_contents(dirname(__DIR__) . '/public/assets/canvas-tools.js');
    foreach (['up', 'down', 'duplicate', 'remove'] as $action) {
        assertContains("['{$action}',", $script, "canvas-tools.js offers no {$action} control");
    }
    $builder = (string) file_get_contents(dirname(__DIR__) . '/public/assets/builder.js');
    assertContains("event.data.type === 'action' && api.act", $builder, 'builder.js does not route a canvas action');
    // builder-actions.js since the split of D-117.
    assertContains('api.act = act;', (string) file_get_contents(dirname(__DIR__) . '/public/assets/builder-actions.js'), 'act() is not exposed');

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

testBothDrivers('the band endpoint answers with an empty band and its fields', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', true, [
        ['type' => 'text', 'content' => ['body' => '<p>Stored</p>']],
    ]);
    $before = blocksWithStyle($db, $id);

    // WHAT "+ SECTION" ASKS FOR (D-101): a band with nothing in it. It is the thing the
    // author just added and is about to fill, so the editor has to be able to draw it —
    // and it needs the band's own fields, because a band with no fields is one nothing can
    // be done to.
    $response = adminPost("/admin/pages/{$id}/section", [
        'section' => ['layout' => 'one', 'stack' => 'stack'],
    ]);

    assertEquals(200, $response->status, 'status');
    if (preg_match('~<template data-band-canvas>(.*?)</template>~s', $response->body, $drawn) !== 1) {
        fail('the endpoint returned no band');
    }
    assertContains('section-cols cols-one', $drawn[1], 'an empty band is drawn as the column it has');
    assertEquals(1, substr_count($drawn[1], '<div class="section-column">'), 'its one empty column');
    assertContains('<template data-section-fields>', $response->body, 'the band came without its fields');
    assertContains('sections[m0][layout]', $response->body, 'the fields are named for a band the browser will rename');

    // It writes nothing: the band exists only in the page being edited until Save.
    assertEquals($before, blocksWithStyle($db, $id), 'the endpoint wrote to the page');
    assertEquals(1, count(App\Modules\Pages\Sections::forPage($db, $id)), 'the endpoint made a band in the database');
});

testBothDrivers('an empty band is drawn in the editor and never on the page', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', true, [
        ['type' => 'text', 'content' => ['body' => '<p>Stored</p>']],
    ]);
    // A band with nothing in it, written the way a save would write one that has just lost
    // its last block before prune() runs.
    $db->query(
        'INSERT INTO page_sections (page_id, sort, layout, stack, style_json, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$id, 9, 'halves', 'stack', '{}', gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')],
    );

    // To a visitor it is a surface and a rhythm around nothing.
    $front = dispatch('/about')->body;
    assertEquals(1, substr_count($front, '<section class="block'), 'the visitor was shown an empty band');

    // To an author it is the band they just added and are about to fill, so the canvas has
    // it — and a "+ Section" that appeared to do nothing would be the editor lying.
    $canvas = dispatch("/admin/pages/{$id}/canvas")->body;
    assertEquals(2, substr_count($canvas, '<section data-bx-section='), 'the editor hid the empty band');
    assertContains('cols-halves', $canvas, 'the empty band was drawn without its shape');
});
