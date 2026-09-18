<?php

use App\Modules\Pages\BlockForm;
use App\Modules\Pages\BlockPreview;

// The Columns block (PLAN.md D-008, D-041): one block, two to four columns in a row, every
// column the same bounded content. Asserted on the served page wherever it can be.
// adminSite() and adminPost() come from pages_admin_test.php.

/**
 * A published page holding one Columns block.
 *
 * @param list<array<string, mixed>> $items
 * @param array<string, mixed> $extra the block's own fields beside its items
 */
function columnsPage(App\Core\Db $db, array $items, string $layout = 'three', array $extra = []): string
{
    createPage($db, 'en', 'grid', 'Grid', true, [
        ['type' => 'columns', 'content' => ['items' => $items] + $extra, 'layout' => $layout],
    ]);

    return dispatch('/grid')->body;
}

testBothDrivers('columns draw a heading, an introduction and one column per item', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $about = createPage($db, 'en', 'about', 'About us');
    $body = columnsPage($db, [
        ['heading' => 'Design', 'body' => '<p>One <strong>character</strong>.</p>', 'link' => ['label' => 'More', 'url' => 'page:' . $about]],
        ['heading' => 'Build', 'body' => '<p>Pages you edit.</p>'],
        ['heading' => 'Care'],
    ], 'three', ['heading' => 'What we do', 'intro' => 'Three things.']);

    assertContains('layout-three', $body, 'the layout class on the section');
    assertContains('<h2 class="columns-heading">What we do</h2>', $body, 'the heading');
    assertContains('<p class="columns-intro">Three things.</p>', $body, 'the introduction');
    assertEquals(3, substr_count($body, '<div class="columns-item'), 'one column per item');
    assertContains('<h3 class="columns-item-heading">Design</h3>', $body, 'a column heading');
    assertContains('<p>One <strong>character</strong>.</p>', $body, 'a column\'s rich text');
    // A column's link is a page reference like any other (D-034).
    assertContains('<a href="/about">More</a>', $body, 'a column\'s link');
    assertEquals(1, substr_count($body, 'class="columns-link"'), 'a link drawn for a column that has none');
});

testBothDrivers('an empty column is drawn, and marked so the editor can outline it', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $body = columnsPage($db, [['heading' => 'One'], [], ['heading' => 'Three']]);

    // Drawn: the canvas and the page show the same grid, and a hole is seen before it is
    // published rather than after.
    assertEquals(3, substr_count($body, '<div class="columns-item'), 'columns drawn');
    assertEquals(1, substr_count($body, 'columns-item is-empty'), 'the empty column is not marked');
});

testBothDrivers('a column\'s picture takes the block\'s shape, sized for how many share a row', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $picture = storedPicture($db, 'portrait', [
        'card' => ['width' => 600, 'height' => 400, 'formats' => ['webp', 'jpg']],
        'wide' => ['width' => 1200, 'height' => 630, 'formats' => ['webp', 'jpg']],
    ]);
    $body = columnsPage($db, [['image' => $picture, 'heading' => 'Ana'], ['heading' => 'No picture']], 'four', ['image_shape' => 'round']);

    assertContains('class="columns shape-round"', $body, 'the shape');
    assertContains('m/card/' . $picture . '-portrait', $body, 'the card variant');
    assertContains('sizes="(max-width: 40rem) 100vw, 25vw"', $body, 'sizes for a row of four');
    // A column without a picture is a column of words, not a grey box.
    assertEquals(1, substr_count($body, '<div class="columns-media">'), 'a picture area drawn for a column without one');
});

testBothDrivers('a picture chosen and since deleted keeps its place as a placeholder', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    // Rendered straight from content, as a page whose picture went after it was saved.
    $html = blockRegistry()->render('columns', blockRegistry()->normalize('columns', ['items' => [['image' => 999, 'heading' => 'Gone']]]), [], 'two');

    assertContains('<div class="media-placeholder" data-media-id="999"', $html, 'the placeholder');
});

test('a new Columns block starts with one row of empty columns', function () {
    $content = blockRegistry()->fresh('columns');

    assertEquals(3, count($content['items']), 'items a new block starts with');
    assertEquals(['image' => null, 'heading' => '', 'body' => '', 'link' => ['label' => '', 'url' => '']], $content['items'][0], 'an empty item');
    // A block with no repeater starts exactly as it always did.
    assertEquals(blockRegistry()->normalize('hero', []), blockRegistry()->fresh('hero'), 'a block without a repeater');
});

testBothDrivers('the editor inserts a Columns block with its three columns outlined', function (string $driver) {
    $db = adminSite($driver);
    $page = createPage($db, 'en', 'grid', 'Grid');

    $response = adminPost("/admin/pages/{$page}/block", ['type' => 'columns']);
    assertEquals(200, $response->status, 'status');
    assertEquals(3, substr_count($response->body, 'columns-item is-empty'), 'empty columns on the canvas');
    assertContains('name="blocks[0][items][2][heading]"', $response->body, 'the third item\'s fields in the inspector');
});

test('a Columns block with no columns is refused on save', function () {
    $parsed = BlockForm::parse(blockRegistry(), [['type' => 'columns', 'heading' => 'Empty']], []);

    assertEquals(t('pages.field.required'), $parsed['errors']['0.items'] ?? null, 'a block of no columns was let through');
});

test('the library shows Columns as a row of sample columns', function () {
    $dir = tmpPath('previews-columns');
    removeTree($dir);
    $file = BlockPreview::file(blockRegistry(), 'columns', 'tokens.test.css', $dir);
    $html = (string) file_get_contents($dir . '/previews/' . $file);

    assertEquals(3, substr_count($html, '<h3 class="columns-item-heading">' . e(t('preview.heading')) . '</h3>'), 'sample columns');
});
