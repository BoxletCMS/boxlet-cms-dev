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

test('the shipped blocks are hero, image_text and text, and all valid', function () {
    assertEquals(['hero', 'image_text', 'text'], Blocks::discover(dirname(__DIR__) . '/app/Blocks')->types(), 'types');
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
    assertContains('class="block block-hero layout-center"', $blocks->render('hero', ['heading' => 'x'], [], 'gone'), 'render with a removed layout');
});

test('every block, layout, field and select option has an admin label in lang/en.php', function () {
    $strings = require dirname(__DIR__) . '/lang/en.php';
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
        }
        foreach ($keys as $key) {
            assertTrue(isset($strings[$key]), "lang/en.php is missing {$key}");
        }
    }
});

test('rendering escapes content and puts type and layout classes on the wrapper', function () {
    $blocks = Blocks::discover(dirname(__DIR__) . '/app/Blocks');
    $html = $blocks->render('hero', ['heading' => '<script>alert(1)</script>', 'cta' => ['label' => 'Go', 'url' => '/go']]);

    assertContains('<section class="block block-hero layout-center">', $html, 'wrapper');
    assertContains('&lt;script&gt;alert(1)&lt;/script&gt;', $html, 'escaped heading');
    assertTrue(!str_contains($html, '<script>'), 'raw script tag in output');
    assertContains('<a class="button" href="/go">Go</a>', $html, 'button');
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

    // In the stylesheets, these properties may only take a custom property.
    // \s*+ is possessive: without it the lookahead could match after backtracking over a space.
    $mustUseVar = '~^\s*(color|background|background-color|border-color|font-family|font-size|box-shadow|border-radius)\s*:\s*+(?!var\(|inherit|transparent|none|currentColor|0;)([^;]+);~mi';
    foreach (['site.css', 'admin.css', 'admin-pages.css'] as $css) {
        $source = (string) file_get_contents($root . '/public/assets/' . $css);
        assertTrue(!preg_match($literal, $source, $match), "{$css} contains the literal " . ($match[0] ?? ''));
        assertTrue(!preg_match($mustUseVar, $source, $match), "{$css} sets " . trim($match[0] ?? '') . ' without a custom property');
    }
});
