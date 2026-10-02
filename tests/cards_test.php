<?php

use App\Modules\Pages\BlockForm;
use App\Modules\Pages\BlockPreview;

// The Cards block (PLAN.md D-008, D-041; the Columns block, renamed with D-166): one block,
// two to four cards in a row, every card the same bounded content. Asserted on the served page
// wherever it can be.
// adminSite() and adminPost() come from pages_admin_test.php.

/**
 * A published page holding one Cards block.
 *
 * @param list<array<string, mixed>> $items
 * @param array<string, string> $options the block's options (D-166)
 * @param array<string, mixed> $extra the block's own fields beside its items
 */
function cardsPage(App\Core\Db $db, array $items, string $layout = 'grid', array $options = [], array $extra = []): string
{
    createPage($db, 'en', 'grid', 'Grid', true, [
        ['type' => 'cards', 'content' => ['items' => $items] + $extra, 'layout' => $layout, 'options' => $options],
    ]);

    return dispatch('/grid')->body;
}

testBothDrivers('cards draw a heading, an introduction and one card per item', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $about = createPage($db, 'en', 'about', 'About us');
    $body = cardsPage($db, [
        ['heading' => 'Design', 'body' => '<p>One <strong>character</strong>.</p>', 'link' => ['label' => 'More', 'url' => 'page:' . $about]],
        ['heading' => 'Build', 'body' => '<p>Pages you edit.</p>'],
        ['heading' => 'Care'],
    ], 'grid', [], ['heading' => 'What we do', 'intro' => 'Three things.']);

    assertContains('layout-grid', $body, 'the layout class on the section');
    assertContains('class="cards per-row-3 shape-wide"', $body, 'three in a row, landscape: the options\' defaults');
    assertContains('<h2 class="cards-heading">What we do</h2>', $body, 'the heading');
    assertContains('<p class="cards-intro">Three things.</p>', $body, 'the introduction');
    assertEquals(3, substr_count($body, '<div class="cards-item'), 'one column per item');
    assertContains('<h3 class="cards-item-heading">Design</h3>', $body, 'a column heading');
    assertContains('<p>One <strong>character</strong>.</p>', $body, 'a column\'s rich text');
    // A column's link is a page reference like any other (D-034).
    assertContains('<a href="/about">More</a>', $body, 'a column\'s link');
    assertEquals(1, substr_count($body, 'class="cards-link"'), 'a link drawn for a column that has none');
});

testBothDrivers('an empty column is drawn, and marked so the editor can outline it', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $body = cardsPage($db, [['heading' => 'One'], [], ['heading' => 'Three']]);

    // Drawn: the canvas and the page show the same grid, and a hole is seen before it is
    // published rather than after.
    assertEquals(3, substr_count($body, '<div class="cards-item'), 'cards drawn');
    assertEquals(1, substr_count($body, 'cards-item is-empty'), 'the empty column is not marked');
});

testBothDrivers('a column\'s picture takes the block\'s shape, sized for how many share a row', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $picture = storedPicture($db, 'portrait', [
        'card' => ['width' => 600, 'height' => 400, 'formats' => ['webp', 'jpg']],
        'wide' => ['width' => 1200, 'height' => 630, 'formats' => ['webp', 'jpg']],
    ]);
    $body = cardsPage($db, [['image' => $picture, 'heading' => 'Ana'], ['heading' => 'No picture']], 'grid', ['per_row' => '4', 'image_shape' => 'round']);

    assertContains('class="cards per-row-4 shape-round"', $body, 'how many in a row, and the shape');
    assertContains('m/card/' . $picture . '-portrait', $body, 'the card variant');
    assertContains('sizes="(max-width: 40rem) 100vw, 25vw"', $body, 'sizes for a row of four');
    // THE RULE CHANGED DELIBERATELY with D-168 (README 1.6): the picture area is part of a
    // card, so a card without a picture shows the placeholder wash — and a card of words is
    // the shape `none`, which draws no area at all.
    assertEquals(2, substr_count($body, '<div class="cards-media">'), 'a picture area for each card');
    assertEquals(1, substr_count($body, '<div class="cards-media"><div class="media-placeholder"'), 'the placeholder for the card without one');
    $words = blockRegistry()->render('cards', blockRegistry()->normalize('cards', ['items' => [['heading' => 'Words only']]]), [], 'grid', [], false, 'none', [], 'en', [], ['image_shape' => 'none']);
    assertTrue(!str_contains($words, 'cards-media'), 'a card of words drew a picture area');
});

testBothDrivers('a picture chosen and since deleted keeps its place as a placeholder', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    // Rendered straight from content, as a page whose picture went after it was saved.
    $html = blockRegistry()->render('cards', blockRegistry()->normalize('cards', ['items' => [['image' => 999, 'heading' => 'Gone']]]), [], 'two');

    assertContains('<div class="media-placeholder" data-media-id="999"', $html, 'the placeholder');
});

test('a new Cards block starts with one row of empty cards', function () {
    $content = blockRegistry()->fresh('cards');

    assertEquals(3, count($content['items']), 'items a new block starts with');
    assertEquals(['image' => null, 'heading' => '', 'body' => '', 'link' => ['label' => '', 'url' => '']], $content['items'][0], 'an empty item');
    // A block with no repeater starts exactly as it always did.
    assertEquals(blockRegistry()->normalize('hero', []), blockRegistry()->fresh('hero'), 'a block without a repeater');
});

testBothDrivers('the editor inserts a Cards block with its three cards outlined', function (string $driver) {
    $db = adminSite($driver);
    $page = createPage($db, 'en', 'grid', 'Grid');

    $response = adminPost("/admin/pages/{$page}/block", ['type' => 'cards']);
    assertEquals(200, $response->status, 'status');
    assertEquals(3, substr_count($response->body, 'cards-item is-empty'), 'empty cards on the canvas');
    assertContains('name="blocks[n0][items][2][heading]"', $response->body, 'the third item\'s fields in the inspector');
});

test('a Cards block with no cards is refused on save', function () {
    $parsed = BlockForm::parse(blockRegistry(), [['type' => 'cards', 'heading' => 'Empty']], []);

    // Errors are keyed by the BLOCK since D-094; a block with no id is n0.
    assertEquals(t('pages.field.required'), $parsed['errors']['n0.items'] ?? null, 'a block of no cards was let through');
});

test('the library shows Cards as a row of sample cards', function () {
    $dir = tmpPath('previews-cards');
    removeTree($dir);
    $file = BlockPreview::file(blockRegistry(), 'cards', 'tokens.test.css', $dir);
    $html = (string) file_get_contents($dir . '/previews/' . $file);

    // The words changed deliberately when a field gained its own 'sample' (D-083); what
    // this test is for has not. It is still: three items, drawn from the repeater.
    assertEquals(3, substr_count($html, '<h3 class="cards-item-heading">' . e(t('preview.cards.item_heading')) . '</h3>'), 'sample cards');
});

test('each block\'s preview says its own words, and a block without a sample still gets one', function () {
    $dir = tmpPath('previews-samples');
    removeTree($dir);
    $registry = blockRegistry();

    $headings = [];
    foreach ($registry->types() as $type) {
        $file = BlockPreview::file($registry, $type, 'tokens.test.css', $dir);
        $headings[$type] = (string) file_get_contents($dir . '/previews/' . $file);
    }

    // Before this, sampleFields() mapped every text field to one generic string, so all
    // five cards read "A heading sits here" and only their shape told them apart.
    assertTrue(str_contains($headings['hero'], e(t('preview.hero.heading'))), 'the hero says its own line');
    assertTrue(str_contains($headings['form'], e(t('preview.form.heading'))), 'the form says its own line');
    assertTrue(!str_contains($headings['hero'], e(t('preview.form.heading'))), 'and they are not the same line');

    // A field that declares nothing keeps the generic sample: that is what lets a block
    // added later have a preview without anyone writing copy for it.
    $definition = ['fields' => ['heading' => ['type' => 'text', 'sample' => null]]];
    assertEquals(t('preview.heading'), BlockPreview::sample($definition)['heading'] ?? null, 'the fallback');
});

/*
 * A ROW SIZE ASKS FOR ITS COLUMNS (PLAN.md D-091).
 *
 * Choosing "four in a row" on a block with three items drew three and an empty
 * cell, and gave no fourth field to type into. The owner reported it as the control not
 * working, and he was right. The block declares what each row size wants; nothing generic
 * knows that a layout called "four" means four.
 */
test('choosing a wider gallery row adds the cells it asks for, and a narrower one keeps them', function () {
    $registry = blockRegistry();
    $three = [['caption' => 'One'], ['caption' => 'Two'], ['caption' => 'Three']];

    $parsed = BlockForm::parse($registry, [['type' => 'gallery', 'layout' => 'four', 'items' => $three]], []);
    $items = $parsed['blocks'][0]['content']['items'] ?? [];
    assertEquals(4, count($items), 'four in a row, with three pictures given');
    assertEquals('', $items[3]['caption'] ?? null, 'the cell it added is empty');
    assertEquals('Three', $items[2]['caption'] ?? null, 'the cells that were there are untouched');

    // Going back to a smaller row is a choice about arrangement. Deleting somebody's
    // writing is not one of its consequences.
    $back = BlockForm::parse($registry, [['type' => 'gallery', 'layout' => 'two', 'items' => $items]], []);
    assertEquals(4, count($back['blocks'][0]['content']['items'] ?? []), 'two in a row threw pictures away');
});

test('a row that is already full is left alone', function () {
    $registry = blockRegistry();
    // Seven pictures at four in a row is a full row and a short one, which is ordinary.
    $seven = array_fill(0, 7, ['caption' => 'x']);
    $parsed = BlockForm::parse($registry, [['type' => 'gallery', 'layout' => 'four', 'items' => $seven]], []);

    assertEquals(7, count($parsed['blocks'][0]['content']['items'] ?? []), 'items after parsing seven');
});

test('the layout options carry what they ask for, so the editor need not guess', function () {
    $db = adminSite('sqlite');
    $page = createPage($db, 'en', 'grid', 'Grid');
    $response = adminPost("/admin/pages/{$page}/block", ['type' => 'gallery']);

    assertEquals(200, $response->status, 'status');
    // The view writes the block's own declaration onto the option; builder-blocks.js reads
    // the number off whichever option was chosen.
    assertContains('data-wants="{&quot;items&quot;:4}"', $response->body, 'the four-in-a-row option');
    assertContains('data-wants="{&quot;items&quot;:2}"', $response->body, 'the two-in-a-row option');
});
