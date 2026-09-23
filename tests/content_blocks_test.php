<?php

use App\Core\Blocks;

/*
 * THE NINE BLOCKS OF D-105 — what each one promises that no other block could keep.
 *
 * blocks_test.php already guards what every block shares: the definition is valid, the
 * labels exist, the icon is in the sprite, the wrapper carries type and layout, content of
 * the wrong shape renders empty rather than fatal. None of that is repeated here.
 *
 * What is here is the reason each block was written instead of being someone's misuse of an
 * existing one. A test that only checked "it renders" would pass on a Quote that drew its
 * attribution as another paragraph, which is precisely the thing it exists not to do.
 */

/**
 * @param array<string, mixed> $content
 * @param array<int, array<string, mixed>> $media
 * @return string the block, rendered bare, with no section wrapper around it.
 */
function drawBlock(string $type, array $content, string $layout = '', array $media = []): string
{
    static $registry = null;
    $registry ??= Blocks::discover(dirname(__DIR__) . '/app/Blocks');

    return $registry->render($type, $content, [], $layout, $media, false, 'none', [], 'en');
}

test('a quote marks up a quotation and its source, not three paragraphs', function (): void {
    $html = drawBlock('quote', [
        'quote' => "Two lines,\nas typed.",
        'attribution' => 'Ana Marić',
        'role' => 'The Bindery',
    ], 'plain');
    assertContains('<blockquote', $html, 'the words are a quotation');
    assertContains('<cite', $html, 'the name is the source');
    assertContains("Two lines,<br />\nas typed.", $html, 'the line break the writer typed');

    // Nothing to attribute means no caption at all, rather than an empty one that still
    // takes its margin — which is what the Text block it replaces could not avoid.
    $bare = drawBlock('quote', ['quote' => 'On its own.'], 'plain');
    assertTrue(!str_contains($bare, 'quote-said'), 'an unattributed quotation still draws its caption');
});

test('a space says nothing to anyone who is listening', function (): void {
    $html = drawBlock('divider', ['height' => 'large'], 'line');
    assertContains('aria-hidden="true"', $html, 'the rule is announced');
    assertContains('height-large', $html, 'how much room');
    // <hr> means a change of topic; this is a change of spacing, and a screen reader that
    // announced it would be reading out the layout.
    assertTrue(!str_contains($html, '<hr'), 'a spacer was marked up as a thematic break');
});

test('a picture keeps its place before a picture is chosen', function (): void {
    $empty = drawBlock('picture', ['caption' => 'The workshop'], 'full');
    assertContains('media-placeholder', $empty, 'no picture, no placeholder, no visible hole');
    assertTrue(!str_contains($empty, 'data-media-id'), 'the placeholder claims a picture nobody chose');
    assertContains('<figcaption', $empty, 'the caption');

    // A picture chosen and since deleted keeps its id on the placeholder, which is how the
    // media screen can say where it was used.
    $deleted = drawBlock('picture', ['image' => 7], 'full');
    assertContains('data-media-id="7"', $deleted, 'a chosen picture that is not resolved');
});

test('a gallery draws every cell, including the ones nobody has filled', function (): void {
    $html = drawBlock('gallery', [
        'heading' => 'Three of them',
        'items' => [['caption' => 'One'], [], ['caption' => 'Three']],
        'shape' => 'square',
    ], 'three');
    assertEquals(3, substr_count($html, 'gallery-item'), 'cells drawn');
    // The empty one is marked, so canvas.css can outline it while editing: a hole that
    // appears only after publishing is the thing this avoids.
    assertEquals(1, substr_count($html, 'is-empty'), 'the empty cell is marked');
    assertContains('shape-square', $html, 'one crop for all of them');
});

test('questions fold by themselves, and only the first one may start open', function (): void {
    $items = [
        ['question' => 'First?', 'answer' => '<p>Yes.</p>'],
        ['question' => 'Second?', 'answer' => '<p>Also yes.</p>'],
    ];
    $open = drawBlock('accordion', ['items' => $items, 'start' => 'first-open'], 'list');
    assertEquals(2, substr_count($open, '<details'), 'one details per question');
    assertEquals(2, substr_count($open, '<summary'), 'the question is the summary');
    assertEquals(1, substr_count($open, ' open>'), 'exactly one question starts open');
    assertContains('<details class="accordion-item" open>', $open, 'and it is the first');

    $closed = drawBlock('accordion', ['items' => $items, 'start' => 'closed'], 'list');
    assertTrue(!str_contains($closed, ' open>'), 'a list asked to start closed did not');

    // No script of any kind: the whole reason for <details>. A folding panel that needs
    // JavaScript is one that does not fold before the script arrives, or when it fails.
    assertTrue(!str_contains($open, 'onclick') && !str_contains($open, '<script'), 'the accordion carries script');
});

test('a call to action asks for one thing, and offers the second more quietly', function (): void {
    $both = drawBlock('cta', [
        'heading' => 'Book a call',
        'body' => 'Half an hour, no charge.',
        'action' => ['label' => 'Book', 'url' => 'mailto:hello@example.com'],
        'second' => ['label' => 'Read first', 'url' => '/about'],
    ], 'banner');
    assertContains('class="button"', $both, 'the main action is the button');
    assertContains('class="cta-quiet"', $both, 'the second is not');
    assertEquals(1, substr_count($both, 'class="button"'), 'two solid buttons is a question, not a call to action');

    // A link with a label and no address is half-written, and half-written is not drawn.
    $none = drawBlock('cta', ['heading' => 'Just words', 'action' => ['label' => 'Book', 'url' => '']], 'banner');
    assertTrue(!str_contains($none, 'cta-links'), 'an unfinished link drew an empty row of them');
});

test('a number and the word under it are one pair, not two lists', function (): void {
    $html = drawBlock('stats', [
        'items' => [['value' => '300+', 'label' => 'clients'], ['value' => '48h', 'label' => 'to answer']],
    ], 'three');
    assertContains('<dl', $html, 'the pairs are a description list');
    assertEquals(2, substr_count($html, '<dt'), 'one term per number');
    assertEquals(2, substr_count($html, '<dd'), 'one description per number');
    // Anything a person would write, because "300+" and "48h" are what people write.
    assertContains('300+', $html, 'a number that is not a number');
});

test('a logo with no picture shows its name, which is a way to run the block', function (): void {
    $html = drawBlock('logos', [
        'items' => [['name' => 'Marić Bakery'], ['image' => 9, 'name' => 'Sjever Bindery']],
    ], 'row');
    assertContains('logos-name', $html, 'a mark with no picture shows nothing at all');
    assertContains('Marić Bakery', $html, 'the name');
    // With a picture chosen, the name is not drawn twice — it is the picture's alt text,
    // which the media library owns.
    assertEquals(1, substr_count($html, 'logos-name'), 'the name was drawn beside its own picture');
    assertContains('data-media-id="9"', $html, 'a chosen picture that is not resolved');

    // A mark that leads somewhere is a link; one that does not is not, rather than a link
    // to nowhere that a keyboard still stops on.
    $linked = drawBlock('logos', [
        'items' => [['name' => 'A', 'link' => ['label' => 'A', 'url' => 'https://example.com']], ['name' => 'B']],
    ], 'row');
    assertEquals(1, substr_count($linked, '<a class="logos-mark"'), 'exactly one mark is a link');
});

/*
 * ADDING A BLOCK GIVES YOU SOMETHING TO TYPE IN.
 *
 * Blocks::fresh() fills every repeater with empty items, so a block arrives with rows
 * rather than with an "Add" button and nothing under it. columns_test asserts this for
 * Columns; here it is asserted for every block at once, because six of the nine new ones
 * hold a repeater and the one that did not would be found only by adding it by hand.
 *
 * WRITTEN AFTER GETTING IT WRONG. The rule was first put into BlockForm::fillRows(), on the
 * strength of Blocks::normalize() returning no items — which is true and is not the path a
 * new block takes. fresh() is. The fix was reverted; this is what was actually worth having.
 */
test('every block with a repeater arrives with rows in it, not an empty Add button', function (): void {
    $registry = blockRegistry();
    foreach ($registry->types() as $type) {
        foreach ($registry->get($type)['fields'] as $name => $field) {
            if ($field['type'] !== 'repeater') {
                continue;
            }
            $items = $registry->fresh($type)[$name] ?? null;
            assertTrue(
                is_array($items) && $items !== [],
                "a new {$type} offers no {$name} to fill in",
            );
            // And each row has every field of an item, at its empty value: a row missing a
            // field is a field the owner cannot reach until they save and come back.
            assertEquals(
                array_keys($field['fields']),
                array_keys(is_array($items[0] ?? null) ? $items[0] : []),
                "a new {$type}'s first {$name} row is missing fields",
            );
        }
    }
});
