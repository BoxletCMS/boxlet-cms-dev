<?php

use App\Core\Blocks;
use App\Modules\Design\Derived;
use App\Modules\Design\Presets;
use App\Modules\Design\TokenCompiler;
use App\Modules\Design\Tokens;

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

test('every shipped block is valid, and the set is the one PLAN.md names', function () {
    // The review's §2.3 set, written in D-105 — the blocks whose absence made an owner
    // abuse an existing one — beside the five that shipped first.
    assertEquals(
        [
            'accordion', 'columns', 'cta', 'divider', 'embed', 'form', 'gallery', 'hero',
            'image_text', 'logos', 'picture', 'quote', 'stats', 'text',
        ],
        Blocks::discover(dirname(__DIR__) . '/app/Blocks')->types(),
        'types',
    );
});

test('a valid definition passes and gets its optional flags filled in', function () {
    $definition = Blocks::validate('sample', validBlock());

    // 'sample' joined the optional flags when a field gained the right to say what it
    // shows in a library preview (D-083, SPEC §5.3). It is filled in like the others, so a
    // definition that declares none still has the key and nothing has to test for it.
    assertEquals(
        ['type' => 'text', 'required' => true, 'translatable' => false, 'sample' => null],
        $definition['fields']['heading'],
        'heading field',
    );
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

/*
 * A FRONT-END STYLESHEET MAY ONLY READ A TOKEN THAT EXISTS (PLAN.md D-105).
 *
 * The rule below says a colour, a size or a font must come from a custom property. It does
 * NOT say the property is one anybody defines — and a var() naming a token that does not
 * exist is SILENT: the declaration is dropped and the element inherits. `--text-l` for
 * `--text-lg` shipped a quotation set at body size, and looked merely underwhelming rather
 * than broken. Three names were wrong before this test existed, and the screenshot is what
 * caught them.
 *
 * ONLY A var() WITH NO FALLBACK. `var(--chrome-header-bg, …)` is the documented shape of a
 * token that is emitted only when the owner set one (D-076): absent, it is not a colour that
 * is wrong, it is the palette's shade standing as before. A var() with nothing behind it is
 * making a promise, and this is the test of it.
 *
 * WHAT COUNTS AS DEFINED: what the design layer compiles, over every preset, plus what the
 * front-end stylesheets define for themselves — `--section-*` in sections.css, `--page-*` in
 * chrome.css. Both halves are read rather than listed, so a token added to either needs no
 * line here.
 */
test('every token a front-end stylesheet reads without a fallback is one something defines', function () {
    $root = dirname(__DIR__);
    $sheets = ['site.css', 'blocks-hero.css', 'blocks.css', 'blocks-words.css', 'blocks-media.css', 'chrome.css', 'chrome-header.css', 'sections.css'];
    // Comments first: this file explains itself with `var(--section-*)` in prose, and a
    // scanner that cannot tell prose from a declaration reports the prose.
    $strip = static fn (string $css): string => (string) preg_replace('~/\*.*?\*/~s', '', $css);

    $sources = [];
    foreach (Presets::names() as $name) {
        $sources[] = (new TokenCompiler())->css(Derived::from(Presets::get($name)));
    }
    // And a design with a colour of its own on the header and the footer: the --chrome-*
    // tokens exist only then (D-076), and since D-110 chrome.css reads them with no fallback
    // under the `own-colour` class the template emits under that same condition. The rule
    // stays what it was — a token read with no fallback is one the design layer defines —
    // and this is the design that defines them.
    $sources[] = (new TokenCompiler())->css(Derived::from(Tokens::validate(
        ['header_colour' => '#1b3a2f', 'footer_colour' => '#f3e9d2'] + Presets::get(Presets::DEFAULT),
    )['decisions']));
    foreach ($sheets as $css) {
        $sources[] = $strip((string) file_get_contents($root . '/public/assets/' . $css));
    }
    $defined = [];
    foreach ($sources as $source) {
        preg_match_all('~(--[a-z0-9]+(?:-[a-z0-9]+)*)\s*:~', $source, $found);
        foreach ($found[1] as $name) {
            $defined[$name] = true;
        }
    }

    $missing = [];
    foreach ($sheets as $css) {
        $source = $strip((string) file_get_contents($root . '/public/assets/' . $css));
        preg_match_all('~var\(\s*(--[a-z0-9]+(?:-[a-z0-9]+)*)\s*\)~', $source, $found);
        foreach ($found[1] as $name) {
            if (!isset($defined[$name])) {
                $missing[] = "{$css} reads {$name}";
            }
        }
    }
    assertEquals([], array_values(array_unique($missing)), 'tokens read with no fallback and never defined');
});

/*
 * A CUSTOM PROPERTY MAY NOT NAME ITSELF, not even in a fallback (D-110).
 *
 * `--section-link: var(--chrome-footer-text, var(--section-link))` was written in the belief
 * that the inner var() would read the value inherited from the parent. The CSS cascade does
 * not work that way: a declaration that references its own property is a cycle on whatever
 * element it sits, and a cycle makes the property invalid at computed-value time — silently.
 * Measured on the served page: every --section-* token on the footer's container was "",
 * and the links inside a contrast footer took the page's accent at 2.43:1.
 *
 * Comments are stripped first, for the same reason as above: the explanation of the rule
 * names the shape the rule forbids.
 */
test('no front-end stylesheet defines a custom property in terms of itself', function () {
    $root = dirname(__DIR__);
    $strip = static fn (string $css): string => (string) preg_replace('~/\*.*?\*/~s', '', $css);

    $cycles = [];
    foreach (['site.css', 'blocks-hero.css', 'blocks.css', 'blocks-words.css', 'blocks-media.css', 'chrome.css', 'chrome-header.css', 'sections.css'] as $css) {
        $source = $strip((string) file_get_contents($root . '/public/assets/' . $css));
        preg_match_all('~(--[a-z0-9]+(?:-[a-z0-9]+)*)\s*:([^;}]*)[;}]~', $source, $found, PREG_SET_ORDER);
        foreach ($found as [, $name, $value]) {
            if (preg_match('~var\(\s*' . preg_quote($name, '~') . '\s*[,)]~', $value) === 1) {
                $cycles[] = "{$css}: {$name}: " . trim($value);
            }
        }
    }
    assertEquals([], $cycles, 'custom properties that name themselves');
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
    // A colour hides from the line above when it is percent-encoded, which is what a data:
    // URI does to '#'. sections.css carried stroke='%23000' inside a mask for a while and
    // this test passed over it; the rule was never "no # character", it was "no colour of
    // its own" (D-084).
    $encoded = '~%23[0-9a-fA-F]{3,8}\b~';
    foreach (['site.css', 'blocks-hero.css', 'blocks.css', 'blocks-words.css', 'blocks-media.css', 'chrome.css', 'chrome-header.css', 'sections.css'] as $css) {
        $source = (string) file_get_contents($root . '/public/assets/' . $css);
        assertTrue(!preg_match($literal, $source, $match), "{$css} contains the literal " . ($match[0] ?? ''));
        assertTrue(!preg_match($encoded, $source, $match), "{$css} contains the encoded colour " . ($match[0] ?? ''));
        assertTrue(!preg_match($mustUseVar, $source, $match), "{$css} sets " . trim($match[0] ?? '') . ' without a custom property');
    }
});

/*
 * A BLOCK'S ICON HAS TO EXIST (PLAN.md D-084).
 *
 * icon() writes <use href="…icons.svg#i-name">, and a name the sprite does not carry draws
 * NOTHING — no error, no warning, an empty square. tools/icons/build.php says as much and
 * ends with "check the screen", which is a rule nobody remembers on the day they add a
 * block. The sprite is a committed file, so this can simply read it.
 *
 * It also guards the other direction that matters: the five icons were 'hero', 'text',
 * 'image-text', 'columns' and 'form', none of which were ever in the sprite, and nobody
 * noticed for as long as nothing drew them.
 */
test('every shipped block names an icon the sprite actually has', function () {
    $sprite = (string) file_get_contents(dirname(__DIR__) . '/public/assets/vendor/icons.svg');
    $registry = Blocks::discover(dirname(__DIR__) . '/app/Blocks');

    $missing = [];
    foreach ($registry->types() as $type) {
        $name = (string) $registry->get($type)['icon'];
        if (!str_contains($sprite, 'id="i-' . $name . '"')) {
            $missing[] = "{$type} asks for '{$name}'";
        }
    }

    assertEquals([], $missing, 'icons named by a block but not in public/assets/vendor/icons.svg');
});

test('every shipped block says in one line what it is for', function () {
    $registry = Blocks::discover(dirname(__DIR__) . '/app/Blocks');

    $summaries = [];
    foreach ($registry->types() as $type) {
        $key = 'block.' . $type . '.summary';
        $summary = t($key);
        // t() answers with the key itself when lang/ has no such string, which on the screen
        // is a card reading "block.hero.summary" under its name.
        assertTrue($summary !== $key, "{$type} has no {$key} in lang/");
        $summaries[] = $summary;
    }

    assertEquals(count($summaries), count(array_unique($summaries)), 'two blocks share one summary');
});

/*
 * A repeater may say how many items each of its block's layouts wants (D-091, SPEC §5.3).
 * It is optional, it is checked against the block's own layouts, and it cannot ask for more
 * than the repeater holds.
 */
test('per_layout is refused when it asks for the impossible', function () {
    $withRepeater = static fn (array $repeater): array => [
        'type' => 'sample',
        'icon' => 'image',
        'version' => 1,
        'fields' => ['items' => ['type' => 'repeater', 'max' => 4, 'fields' => ['heading' => ['type' => 'text']]] + $repeater],
        'layouts' => ['two', 'four'],
        'defaults' => ['layout' => 'two'],
    ];

    $cases = [
        'not a map' => [['per_layout' => 'four'], "'per_layout' must be a map"],
        'empty' => [['per_layout' => []], "'per_layout' must be a map"],
        'past the maximum' => [['per_layout' => ['four' => 9]], 'between 1 and the repeater\'s max of 4'],
        'not a number' => [['per_layout' => ['four' => 'four']], 'between 1 and the repeater\'s max of 4'],
        'a layout this block has not got' => [['per_layout' => ['five' => 4]], "is not one of this block's layouts"],
    ];
    foreach ($cases as $name => [$repeater, $expected]) {
        $thrown = null;
        try {
            Blocks::validate('sample', $withRepeater($repeater));
        } catch (Throwable $e) {
            $thrown = $e->getMessage();
        }
        assertTrue($thrown !== null && str_contains($thrown, $expected), "{$name}: got " . var_export($thrown, true));
    }

    // And the shipped Columns block, which is the one that needed this.
    $columns = Blocks::discover(dirname(__DIR__) . '/app/Blocks')->get('columns');
    assertEquals(['two' => 2, 'three' => 3, 'four' => 4], $columns['fields']['items']['per_layout'], 'what Columns declares');
});

test('every block a page can hold says which shelf it sits on', function () {
    // `group` is optional in the definition because the site's chrome goes through the same
    // validator, and a header and a footer are the two blocks that can never be ADDED —
    // a shelf in the library is a thing they cannot have (D-104). A PAGE block that leaves
    // it out would fall off the filter silently, so it is caught here instead.
    $blocks = Blocks::discover(dirname(__DIR__) . '/app/Blocks');
    foreach ($blocks->types() as $type) {
        $group = $blocks->get($type)['group'] ?? null;
        assertTrue(
            is_string($group) && in_array($group, App\Core\BlockDefinition::GROUPS, true),
            "block {$type} declares no shelf for the library (got " . var_export($group, true) . ')',
        );
    }

    // And the chrome says nothing, which is the point of the key being optional.
    $chrome = Blocks::discover(dirname(__DIR__) . '/app/Chrome');
    foreach ($chrome->types() as $type) {
        assertEquals(null, $chrome->get($type)['group'] ?? null, "the site's {$type} claims a shelf in the library");
    }
});
