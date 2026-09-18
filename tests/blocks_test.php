<?php

use App\Core\Blocks;

// The block registry and the block contract (SPEC §5.3).

/**
 * A valid definition to break one key at a time.
 *
 * @return array<string, mixed>
 */
function validBlock(): array
{
    return [
        'type' => 'sample',
        'icon' => 'sample',
        'version' => 1,
        'fields' => [
            'heading' => ['type' => 'text', 'required' => true],
            'fit' => ['type' => 'select', 'options' => ['a', 'b']],
        ],
        'layouts' => ['one', 'two'],
        'defaults' => ['layout' => 'one'],
    ];
}

test('the shipped blocks are columns, hero, image_text and text, and all valid', function () {
    assertEquals(['columns', 'hero', 'image_text', 'text'], Blocks::discover(dirname(__DIR__) . '/app/Blocks')->types(), 'types');
});

test('a valid definition passes and gets its optional flags filled in', function () {
    $definition = Blocks::validate('sample', validBlock());

    assertEquals(['type' => 'text', 'required' => true, 'translatable' => false], $definition['fields']['heading'], 'heading field');
});

// Each case breaks one thing; the message must name the block and, for a field, the field.
$malformed = [
    'not an array' => [fn () => 'nope', 'Block sample: block.php must return an array'],
    'missing key' => [fn () => array_diff_key(validBlock(), ['icon' => 0]), "Block sample: missing key 'icon'"],
    'unknown key' => [fn () => validBlock() + ['colour' => 'red'], "Block sample: unknown key 'colour'"],
    'type differs from directory' => [fn () => ['type' => 'other'] + validBlock(), "Block sample: 'type' must equal the directory name"],
    'label is no longer part of the contract' => [fn () => ['label' => 'Sample'] + validBlock(), "Block sample: unknown key 'label'"],
    'empty icon' => [fn () => ['icon' => ' '] + validBlock(), "Block sample: 'icon' must be a non-empty string"],
    'version zero' => [fn () => ['version' => 0] + validBlock(), "Block sample: 'version' must be an integer"],
    'version as string' => [fn () => ['version' => '1'] + validBlock(), "Block sample: 'version' must be an integer"],
    'no fields' => [fn () => ['fields' => []] + validBlock(), "Block sample: 'fields' must be a non-empty array"],
    'unknown field type' => [fn () => ['fields' => ['x' => ['type' => 'colour']]] + validBlock(), "Block sample: field 'x': 'type' must be one of"],
    'unimplemented field type' => [fn () => ['fields' => ['x' => ['type' => 'toggle']]] + validBlock(), "Block sample: field 'x': field type 'toggle' is in the closed set but not implemented yet"],
    'unknown field key' => [fn () => ['fields' => ['x' => ['type' => 'text', 'label' => 'X']]] + validBlock(), "Block sample: field 'x': unknown key 'label'"],
    'required not boolean' => [fn () => ['fields' => ['x' => ['type' => 'text', 'required' => 'yes']]] + validBlock(), "Block sample: field 'x': 'required' must be true or false"],
    'bad field name' => [fn () => ['fields' => ['Heading' => ['type' => 'text']]] + validBlock(), "Block sample: field 'Heading': field names must match"],
    'reserved field name' => [fn () => ['fields' => ['type' => ['type' => 'text']]] + validBlock(), "Block sample: field 'type': the name is reserved by the page editor"],
    'select without options' => [fn () => ['fields' => ['x' => ['type' => 'select']]] + validBlock(), "Block sample: field 'x': a select needs 'options'"],
    'options on a text field' => [fn () => ['fields' => ['x' => ['type' => 'text', 'options' => ['a']]]] + validBlock(), "Block sample: field 'x': only select fields take 'options'"],
    // A repeater declares one item and how many of them (PLAN.md O-11). It was already in
    // the closed FIELD_TYPES list and simply unimplemented, so these check the declaration,
    // not a new type.
    'repeater without max' => [fn () => ['fields' => ['x' => ['type' => 'repeater', 'fields' => ['y' => ['type' => 'text']]]]] + validBlock(), "Block sample: field 'x': a repeater needs 'max'"],
    'repeater with max zero' => [fn () => ['fields' => ['x' => ['type' => 'repeater', 'max' => 0, 'fields' => ['y' => ['type' => 'text']]]]] + validBlock(), "Block sample: field 'x': a repeater needs 'max'"],
    'repeater without fields' => [fn () => ['fields' => ['x' => ['type' => 'repeater', 'max' => 2]]] + validBlock(), "Block sample: field 'x': a repeater needs 'fields'"],
    'repeater with options' => [fn () => ['fields' => ['x' => ['type' => 'repeater', 'max' => 2, 'fields' => ['y' => ['type' => 'text']], 'options' => ['a']]]] + validBlock(), "Block sample: field 'x': a repeater does not take 'options'"],
    'max on a text field' => [fn () => ['fields' => ['x' => ['type' => 'text', 'max' => 2]]] + validBlock(), "Block sample: field 'x': only a repeater takes 'max'"],
    'a repeater inside a repeater' => [fn () => ['fields' => ['x' => ['type' => 'repeater', 'max' => 2, 'fields' => ['y' => ['type' => 'repeater', 'max' => 2, 'fields' => ['z' => ['type' => 'text']]]]]]] + validBlock(), "a repeater cannot hold another repeater"],
    'a bad field inside an item' => [fn () => ['fields' => ['x' => ['type' => 'repeater', 'max' => 2, 'fields' => ['y' => ['type' => 'colour']]]]] + validBlock(), "Block sample: field 'y': 'type' must be one of"],
    'no layouts' => [fn () => ['layouts' => []] + validBlock(), "Block sample: 'layouts' must be a non-empty list"],
    'duplicate layout' => [fn () => ['layouts' => ['one', 'one']] + validBlock(), "Block sample: 'layouts' contains a duplicate"],
    'default layout not offered' => [fn () => ['defaults' => ['layout' => 'three']] + validBlock(), "Block sample: 'defaults' must be"],
];
foreach ($malformed as $case => [$make, $message]) {
    test("a malformed definition is rejected: {$case}", function () use ($make, $message) {
        assertThrows(fn () => Blocks::validate('sample', $make()), $message);
    });
}

test('discovery fails loudly for a block without a template', function () {
    $dir = tmpPath('blocks');
    removeTree($dir);
    mkdir($dir . '/sample', 0700, true);
    file_put_contents($dir . '/sample/block.php', '<?php return ' . var_export(validBlock(), true) . ';');

    assertThrows(fn () => Blocks::discover($dir), 'Block sample: missing template.php');
});

test('a broken block stops the application at boot', function () {
    $dir = tmpPath('blocks');
    removeTree($dir);
    mkdir($dir . '/sample', 0700, true);
    file_put_contents($dir . '/sample/block.php', '<?php return ' . var_export(['version' => 'x'] + validBlock(), true) . ';');
    file_put_contents($dir . '/sample/template.php', '');

    assertThrows(fn () => Blocks::discover($dir), "'version' must be an integer");
});

test('a layout the block does not declare falls back to its default', function () {
    $blocks = Blocks::discover(dirname(__DIR__) . '/app/Blocks');

    assertEquals('split', $blocks->layout('hero', 'split'), 'declared layout');
    assertEquals('center', $blocks->layout('hero', 'image-left'), "another block's layout");
    assertEquals('center', $blocks->layout('hero', ''), 'empty');
    assertEquals('center', $blocks->layout('hero', ['split']), 'wrong shape');
    assertContains('class="block block-hero layout-center ', $blocks->render('hero', ['heading' => 'x'], [], 'gone'), 'render with a removed layout');
});

test('every block, layout, field and select option has an admin label', function () {
    // Asked of t(), not of the files. Reading one file broke when the strings were split
    // by concern; globbing the directory then broke again when they were nested by locale.
    // t() is what the product uses, so a test that asks it is immune to how they are
    // arranged — and t() returns the key itself when nothing is defined, which is exactly
    // the failure this is looking for.
    $blocks = Blocks::discover(dirname(__DIR__) . '/app/Blocks');
    foreach ($blocks->types() as $type) {
        $keys = ["block.{$type}"];
        foreach ($blocks->get($type)['layouts'] as $layout) {
            $keys[] = "block.{$type}.layout.{$layout}";
        }
        foreach ($blocks->get($type)['fields'] as $name => $field) {
            $keys[] = "block.{$type}.{$name}";
            foreach ($field['options'] ?? [] as $option) {
                $keys[] = "block.{$type}.{$name}.{$option}";
            }
            // A repeater's items are fields too, and each one carries its own label in the
            // editor (PLAN.md O-11). The third segment names an item's field where a
            // select's names an option, and the two can never collide: a repeater refuses
            // 'options' and a select cannot take 'fields'.
            //
            // This covers nothing today, because no shipped block has a repeater yet. It is
            // written now so the Columns block (D-008) cannot arrive with unlabelled item
            // fields — t() returns the key itself when nothing is defined, so the failure
            // this prevents is the raw string "block.columns.items.heading" on screen.
            foreach ($field['fields'] ?? [] as $itemName => $itemField) {
                $keys[] = "block.{$type}.{$name}.{$itemName}";
                foreach ($itemField['options'] ?? [] as $option) {
                    $keys[] = "block.{$type}.{$name}.{$itemName}.{$option}";
                }
            }
        }
        foreach ($keys as $key) {
            assertTrue(t($key) !== $key, "no admin label anywhere in lang/ for {$key}");
        }
    }
});

test('rendering escapes content and puts type and layout classes on the wrapper', function () {
    $blocks = Blocks::discover(dirname(__DIR__) . '/app/Blocks');
    $html = $blocks->render('hero', ['heading' => '<script>alert(1)</script>', 'cta' => ['label' => 'Go', 'url' => '/go']]);

    assertContains('<section class="block block-hero layout-center surface-plain rhythm-normal width-normal align-left divider-none">', $html, 'wrapper');
    assertContains('&lt;script&gt;alert(1)&lt;/script&gt;', $html, 'escaped heading');
    assertTrue(!str_contains($html, '<script>'), 'raw script tag in output');
    assertContains('<a class="button" href="/go">Go</a>', $html, 'button');
});

// The architect's hero ruling, 2026-09-17. image_text has always drawn its placeholder
// unconditionally — only data-media-id depends on a picture being chosen — while hero drew
// no media element at all, so a layout that had reserved half the section for a picture
// left it empty. Seen on the demo's /services once the seed stopped shipping media ids.
test('a hero layout that reserves a picture area always draws the placeholder', function () {
    $blocks = Blocks::discover(dirname(__DIR__) . '/app/Blocks');

    $splitEmpty = $blocks->render('hero', ['heading' => 'x'], [], 'split');
    assertContains('hero-media', $splitEmpty, 'split with no picture draws no media area');
    assertContains('media-placeholder', $splitEmpty, 'split with no picture draws no placeholder');
    assertTrue(!str_contains($splitEmpty, 'data-media-id'), 'a placeholder with no picture claims a media id');

    assertContains('data-media-id="7"', $blocks->render('hero', ['heading' => 'x', 'image' => 7], [], 'split'), 'split with a picture');

    // A layout that reserves nothing draws nothing: a centred hero has no picture area.
    $centred = $blocks->render('hero', ['heading' => 'x'], [], 'center');
    assertTrue(!str_contains($centred, 'hero-media'), 'a centred hero reserves no picture area but drew one');
    assertContains('hero-media', $blocks->render('hero', ['heading' => 'x', 'image' => 7], [], 'center'), 'a centred hero with a picture no longer shows it');

    // The rules that re-flowed a split hero WITHOUT a media element can never match again.
    // Dead CSS explaining a case that cannot arise is worse than none: it reads as
    // deliberate.
    $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/blocks.css');
    assertTrue(!str_contains($css, ':not(:has(.hero-media))'), 'blocks.css still carries the unreachable no-media split rules');
});

test('stored content of the wrong shape renders as empty values, never an error', function () {
    $blocks = Blocks::discover(dirname(__DIR__) . '/app/Blocks');
    $normalized = $blocks->normalize('image_text', ['heading' => ['nested'], 'image' => '7', 'image_fit' => 'stretch', 'link' => 'x']);

    assertEquals(['heading' => '', 'body' => '', 'image' => null, 'image_fit' => 'cover', 'link' => ['label' => '', 'url' => '']], $normalized, 'normalized');
});

test('no block template or front-end stylesheet hard-codes a colour, size, font or shadow', function () {
    $root = dirname(__DIR__);
    $literal = '~#[0-9a-fA-F]{3,8}\b|\b(?:rgb|rgba|hsl|hsla)\(|\d(?:px|pt)\b~';
    foreach (glob($root . '/app/Blocks/*/template.php') ?: [] as $file) {
        $source = (string) file_get_contents($file);
        $name = basename(dirname($file)) . '/template.php';
        assertTrue(!preg_match($literal, $source, $match), "{$name} contains the literal " . ($match[0] ?? ''));
        assertTrue(!preg_match('~\bstyle\s*=|<style|font-family|box-shadow~i', $source, $match), "{$name} contains " . ($match[0] ?? ''));
    }

    // In the front-end stylesheets, these properties may only take a custom property.
    // The admin's stylesheets are deliberately the other way round: they define their own
    // literal values and may never read a site token (tests/admin_test.php).
    // \s*+ is possessive: without it the lookahead could match after backtracking over a space.
    $mustUseVar = '~^\s*(color|background|background-color|border-color|font-family|font-size|box-shadow|border-radius)\s*:\s*+(?!var\(|inherit|transparent|none|currentColor|0;)([^;]+);~mi';
    foreach (['site.css', 'blocks.css', 'chrome.css', 'sections.css'] as $css) {
        $source = (string) file_get_contents($root . '/public/assets/' . $css);
        assertTrue(!preg_match($literal, $source, $match), "{$css} contains the literal " . ($match[0] ?? ''));
        assertTrue(!preg_match($mustUseVar, $source, $match), "{$css} sets " . trim($match[0] ?? '') . ' without a custom property');
    }
});
