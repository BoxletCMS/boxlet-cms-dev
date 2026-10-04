<?php

use App\Modules\Pages\BlockForm;

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

testBothDrivers('the editor draws a new Cards block with its three cards outlined, and their fields', function (string $driver) {
    $db = adminSite($driver);
    $page = createPage($db, 'en', 'grid', 'Grid', true, [['type' => 'text', 'content' => ['body' => '<p>x</p>']]]);
    [, $section] = builderBand($page);
    // As the builder adds one (D-175): the block's fresh content, in a band of the document.
    $block = ['key' => 'n1', 'id' => null, 'type' => 'cards', 'content' => blockRegistry()->fresh('cards'), 'style' => [], 'options' => [], 'layout' => '', 'section' => $section['key'], 'column' => 0];

    $drawn = json_decode(builderRequest("/admin/pages/{$page}/render", ['section' => $section, 'blocks' => [$block]])->body, true)['html'] ?? '';
    assertEquals(3, substr_count($drawn, 'cards-item is-empty'), 'empty cards on the canvas');
    $inspector = json_decode(builderRequest("/admin/pages/{$page}/inspect", ['kind' => 'block', 'key' => 'n1', 'section' => $section, 'blocks' => [$block]])->body, true)['html'] ?? '';
    assertContains('name="blocks[n1][items][2][heading]"', $inspector, 'the third item\'s fields in the inspector');
});

test('a Cards block with no cards is refused on save', function () {
    $parsed = BlockForm::parse(blockRegistry(), [['type' => 'cards', 'heading' => 'Empty']], []);

    // Errors are keyed by the BLOCK since D-094; a block with no id is n0.
    assertEquals(t('pages.field.required'), $parsed['errors']['n0.items'] ?? null, 'a block of no cards was let through');
});

/*
 * A LAYOUT IS AN ARRANGEMENT, NEVER CONTENT (PLAN.md D-186, the owner; it replaces D-091).
 *
 * "Four in a row" with three pictures once had the server add a fourth: in the canvas it
 * drew, in the draft it saved — and never in the builder's document, so the canvas showed an
 * item the document did not hold and undo could not take it back. A layout or an option
 * chosen now changes the layout or the option and nothing else; "+" at the end of the row is
 * how a fourth is added, and it adds it to the document.
 */
test('a wider gallery row keeps the pictures it was given, no more and no fewer', function () {
    $registry = blockRegistry();
    $three = [['caption' => 'One'], ['caption' => 'Two'], ['caption' => 'Three']];
    foreach (['two', 'three', 'four'] as $layout) {
        $parsed = BlockForm::parse($registry, [['type' => 'gallery', 'layout' => $layout, 'items' => $three]], []);
        assertEquals(['One', 'Two', 'Three'], array_column($parsed['blocks'][0]['content']['items'] ?? [], 'caption'), "three pictures at {$layout}");
    }
    $parsed = BlockForm::parse($registry, [['type' => 'stats', 'layout' => 'four', 'items' => [['value' => '12', 'label' => 'years']]]], []);
    assertEquals(1, count($parsed['blocks'][0]['content']['items'] ?? []), 'one number at four in a row');
});

test('a row size chosen in the inspector and a draft saved with it hold the items sent', function () {
    $page = builderPage();
    $answer = json_decode(dispatch("/admin/pages/{$page}/fields", null, 'POST', ['_csrf' => (new App\Core\Session())->csrfToken(), 'blocks' => ['n2' => [
        'type' => 'gallery', 'layout' => 'four', 'items' => [['caption' => 'One'], ['caption' => 'Two'], ['caption' => 'Three']],
    ]]])->body, true);
    assertEquals(['One', 'Two', 'Three'], array_column($answer['block']['content']['items'] ?? [], 'caption'), 'what /fields gives back');

    // The draft goes through PageDocument, which cleans each block with the form's checks.
    $clean = BlockForm::clean(blockRegistry(), 'gallery', ['items' => [['caption' => 'One'], ['caption' => 'Two'], ['caption' => 'Three']]]);
    assertEquals(3, count($clean['items'] ?? []), 'what a draft keeps');
});
