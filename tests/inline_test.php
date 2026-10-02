<?php

use App\Support\Editing;

// Typing on the page (PLAN.md D-178, README 1.4 and 4.4): the canvas names the element that
// shows each field, a visitor's page never does, an empty field is a place to type only in
// the canvas, and what is typed is cleaned and judged by the form's own parser.

testBothDrivers('the canvas names each field where it is shown; a visitor\'s page names nothing', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', true, [
        ['type' => 'hero', 'content' => ['heading' => 'Welcome']],
        ['type' => 'cards', 'content' => ['heading' => 'Three', 'items' => [['heading' => 'One'], ['heading' => 'Two']]]],
    ]);

    $canvas = dispatch("/admin/pages/{$id}/canvas")->body;
    assertContains('<h1 class="hero-heading" data-bx-field="heading"', $canvas, 'the hero\'s heading');
    assertContains('data-bx-field="items.1.heading"', $canvas, 'a card\'s heading, by its place');
    assertContains('data-bx-item="items.1"', $canvas, 'a card, for its tools');
    assertContains('data-bx-add-item="items"', $canvas, 'the cards\' "+"');
    // An empty optional field is drawn in the canvas, with its words to say so.
    assertContains('class="hero-subheading" data-bx-field="subheading" data-bx-placeholder="+ Add subheading"></p>', $canvas, 'the empty subheading as a place to type');

    $page = dispatch('/about')->body;
    foreach (['data-bx-field', 'data-bx-placeholder', 'data-bx-item', 'bx-add-item'] as $mark) {
        assertTrue(!str_contains($page, $mark), "a visitor's page carries {$mark}");
    }
    assertTrue(!str_contains($page, 'hero-subheading'), 'a visitor\'s page draws the empty subheading');
    assertEquals(false, Editing::on(), 'the canvas left the marks on for whatever is drawn next');
});

test('every word a block shows on the page is marked where it is shown, in every block', function () {
    $registry = blockRegistry();
    $say = static fn (string $key): string => site_t($key, 'en', 'samples');
    foreach ($registry->types() as $type) {
        $content = $registry->sampled($type, $say);
        $html = Editing::during(true, static fn (): string => $registry->render($type, $content));
        foreach ($registry->get($type)['fields'] as $name => $field) {
            $words = static fn (array $f): bool => in_array($f['type'], ['text', 'textarea', 'richtext'], true) && $f['inline'];
            if ($field['type'] === 'repeater') {
                foreach ($field['fields'] as $sub => $declared) {
                    if ($words($declared)) {
                        assertContains('data-bx-field="' . $name . '.0.' . $sub . '"', $html, "{$type}: {$name}.0.{$sub} has no mark");
                    }
                }
            } elseif ($words($field)) {
                assertContains('data-bx-field="' . $name . '"', $html, "{$type}: {$name} has no mark");
            }
        }
    }
});

test('a block typed on the page is cleaned by the form\'s parser, its errors named by field', function () {
    $id = builderPage();
    $answer = json_decode(builderRequest("/admin/pages/{$id}/fields", ['block' => [
        'key' => 'b9', 'type' => 'hero', 'layout' => 'center', 'options' => [],
        'content' => ['heading' => '', 'subheading' => 'Kept', 'cta' => ['label' => 'Go', 'url' => 'page:1'], 'image' => null],
    ]])->body, true);

    assertEquals(t('pages.field.required'), $answer['errors']['heading'] ?? null, 'an empty heading, named by its field');
    assertEquals('Kept', $answer['block']['content']['subheading'] ?? null, 'the words');
    assertEquals('page:1', $answer['block']['content']['cta']['url'] ?? null, 'a link to a page, kept as its reference');

    $rich = json_decode(builderRequest("/admin/pages/{$id}/fields", ['block' => [
        'key' => 'n2', 'type' => 'text', 'layout' => 'single', 'options' => [],
        'content' => ['heading' => 'H', 'body' => '<p onclick="x()">Words<script>y()</script></p>'],
    ]])->body, true);
    $body = (string) ($rich['block']['content']['body'] ?? '');
    assertTrue(str_contains($body, 'Words') && !str_contains($body, 'x()') && !str_contains($body, '<script'), 'rich text through the sanitiser: ' . $body);
});

test('the builder knows what each field is, what a repeater\'s "+" adds, and where a link may lead', function () {
    $id = builderPage();
    preg_match('~<script type="application/json" data-pb-data>(.*?)</script>~s', dispatch("/admin/pages/{$id}")->body, $json);
    $inline = json_decode($json[1] ?? '{}', true)['inline'] ?? [];

    assertEquals('text', $inline['fields']['hero']['heading']['type'] ?? null, 'the hero\'s heading is a line');
    assertEquals(true, $inline['fields']['hero']['heading']['required'] ?? null, 'and required');
    assertEquals('richtext', $inline['fields']['cards']['items']['fields']['body']['type'] ?? null, 'a card\'s body is rich text');
    assertTrue(is_array($inline['fields']['text']['body']['allow'] ?? null), 'and what rich text allows');
    assertEquals('One of the three', $inline['items']['cards']['items']['heading'] ?? null, 'a new card says what it is for');
    assertTrue(count(array_filter($inline['pages'] ?? [], static fn (array $p): bool => (bool) preg_match('~^page:\d+$~', $p['ref']))) > 0, 'pages, by reference');
});
