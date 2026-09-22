<?php

// The repeater field (PLAN.md O-11, SPEC §5.3): a field holding a list of items, each
// item a set of ordinary fields. Stored inside content_json, so no schema changed.
//
// THE TRAVERSAL TESTS ARE THE POINT. Two separate walks of block content — the rule that
// nulls a media id naming no picture on save, and the lookup that resolves pictures for
// rendering — both read only the top level. A picture chosen inside an item would have
// worked in the editor and vanished from the page, which is the first bug an owner would
// have met. Both now ask MediaReference::idsIn(); these fail if either goes flat again.

use App\Core\Blocks;
use App\Core\View;
use App\Modules\Media\MediaPicture;
use App\Modules\Media\MediaReference;
use App\Modules\Pages\BlockForm;

/**
 * A block whose only field is a repeater of image + heading + body, discovered from a
 * temporary directory. Not a shipped block: nothing in app/Blocks needs to carry a
 * repeater for the machinery under it to be checked.
 */
function repeaterRegistry(int $max = 3): Blocks
{
    $dir = tmpPath('repeater-blocks-' . $max);
    removeTree($dir);
    mkdir($dir . '/cards', 0700, true);
    $definition = [
        'type' => 'cards',
        'icon' => 'cards',
        'version' => 1,
        'fields' => [
            'items' => [
                'type' => 'repeater',
                'max' => $max,
                'fields' => [
                    'image' => ['type' => 'media'],
                    'heading' => ['type' => 'text'],
                    'body' => ['type' => 'richtext'],
                ],
            ],
        ],
        'layouts' => ['two'],
        'defaults' => ['layout' => 'two'],
    ];
    file_put_contents($dir . '/cards/block.php', '<?php return ' . var_export($definition, true) . ';');
    file_put_contents($dir . '/cards/template.php', '<?php foreach ($content["items"] as $i) { echo e($i["heading"]); }');

    return Blocks::discover($dir);
}

test('a repeater normalizes to a list, whatever was stored', function (): void {
    $registry = repeaterRegistry();

    $empty = $registry->normalize('cards', []);
    assertEquals([], $empty['items'], 'missing value');
    assertEquals([], $registry->normalize('cards', ['items' => 'nonsense'])['items'], 'a string');
    assertEquals([], $registry->normalize('cards', ['items' => 5])['items'], 'a number');

    // Every field of an item is present, so a template never checks whether a key exists —
    // the promise normalize() already made for ordinary fields.
    $one = $registry->normalize('cards', ['items' => [['heading' => 'A']]])['items'];
    assertEquals(['image' => null, 'heading' => 'A', 'body' => ''], $one[0], 'one item, filled in');
});

test('rendering trims past the maximum rather than failing', function (): void {
    $registry = repeaterRegistry(2);
    $items = $registry->normalize('cards', ['items' => [['heading' => 'A'], ['heading' => 'B'], ['heading' => 'C']]])['items'];

    // A definition whose max shrinks must not stop every page that used the old one from
    // drawing. Saving refuses instead; that difference is deliberate.
    assertEquals(2, count($items), 'items kept');
    assertEquals('A', $items[0]['heading'], 'first kept');
    assertEquals('B', $items[1]['heading'], 'second kept');
});

test('saving validates each item by field type, and sanitises richtext per item', function (): void {
    $registry = repeaterRegistry();
    $posted = [[
        'type' => 'cards',
        'items' => [
            ['heading' => "A\nB", 'body' => '<p>ok</p><script>alert(1)</script>', 'image' => '7'],
            ['heading' => 'Second', 'body' => '<p>two</p>', 'image' => ''],
        ],
    ]];

    $parsed = BlockForm::parse($registry, $posted, []);
    // content is null for a block whose type the registry does not know. Indexing straight
    // into it would fail here with an array-access notice instead of a verdict, which is a
    // test that cannot say what went wrong.
    $content = $parsed['blocks'][0]['content'];
    if ($content === null) {
        fail('the registry did not recognise the block, so nothing was parsed');
    }
    $items = $content['items'];

    assertEquals(2, count($items), 'items parsed');
    assertEquals('A B', $items[0]['heading'], 'a line break in a text field became a space');
    assertTrue(!str_contains($items[0]['body'], '<script'), 'the sanitiser did not run inside the item');
    assertEquals(7, $items[0]['image'], 'a media id inside an item');
    assertEquals(null, $items[1]['image'], 'an empty media field inside an item');
});

test('saving refuses more items than the block takes', function (): void {
    $registry = repeaterRegistry(2);
    $posted = [['type' => 'cards', 'items' => [['heading' => 'A'], ['heading' => 'B'], ['heading' => 'C']]]];

    $parsed = BlockForm::parse($registry, $posted, []);
    assertTrue(isset($parsed['errors']['n0.items']), 'over the maximum was accepted silently');
    assertContains('at most', $parsed['errors']['n0.items'], 'the message');
});

test('an item marked for deletion is left out', function (): void {
    $registry = repeaterRegistry();
    $posted = [['type' => 'cards', 'items' => [['heading' => 'A'], ['heading' => 'B', '_delete' => '1']]]];

    $content = BlockForm::parse($registry, $posted, [])['blocks'][0]['content'];
    if ($content === null) {
        fail('the registry did not recognise the block, so nothing was parsed');
    }
    $items = $content['items'];
    assertEquals(1, count($items), 'items kept');
    assertEquals('A', $items[0]['heading'], 'the one kept');
});

/*
 * THE TWO TRAVERSALS. Written as one test because they are one claim: a picture inside an
 * item is a picture, to every part of the system that looks for one.
 */
test('a media id inside an item is found by the traversal both halves use', function (): void {
    $registry = repeaterRegistry();
    $content = $registry->normalize('cards', ['items' => [
        ['image' => 4, 'heading' => 'A'],
        ['image' => 9, 'heading' => 'B'],
        ['heading' => 'no picture'],
    ]]);

    $ids = MediaReference::idsIn($registry, 'cards', $content);
    sort($ids);
    assertEquals([4, 9], $ids, 'ids inside items');

    // A flat traversal would return [] here, and both halves would go quiet.
    assertTrue($ids !== [], 'the traversal went flat again');
});

/*
 * THE EDITOR. The registry these use is a temporary one, so the editor cannot be reached
 * through dispatch(): the application discovers app/Blocks, where no shipped block carries
 * a repeater until the Columns block (D-008). The view is therefore rendered directly,
 * which is what it was already built to allow — block.php takes its registry as a
 * variable, exactly so the block library and the specimen can render without a database.
 */

/**
 * The block editor's field group for one `cards` block, as the editors render it.
 *
 * @param array<string, mixed> $content
 * @param array<string, string> $errors
 */
function renderRepeaterBlock(Blocks $registry, array $content, string $key = 'n0', array $errors = []): string
{
    return (new View(dirname(__DIR__) . '/app/Modules/Pages/views'))->render('admin/block', 'en', [
        // Since D-094 the index only says which group the panel shows; the KEY is what
        // names the fields, so that is what this helper takes.
        'index' => 0,
        'block' => [
            'key' => $key,
            'id' => null,
            'type' => 'cards',
            'content' => $registry->normalize('cards', $content),
            'style' => [],
            'layout' => 'two',
        ],
        'errors' => $errors,
        'character' => 'soft',
        'registry' => $registry,
        'pictures' => [],
        'linkPages' => [],
    ], null);
}

test('the editor names an item two levels deep, so a block and an item reorder apart', function (): void {
    $registry = repeaterRegistry();
    $html = renderRepeaterBlock($registry, ['items' => [['heading' => 'A'], ['heading' => 'B']]], 'b2');

    // THE POINT OF THE NAMING. blocks[n] is rewritten when a block moves and [items][m]
    // when an item moves, and neither touches the other — both editors' renumber() regexes
    // are anchored at the start, so they stop before the item index by construction.
    assertContains('name="blocks[b2][items][0][heading]"', $html, 'the first item');
    assertContains('name="blocks[b2][items][1][heading]"', $html, 'the second item');
    assertContains('name="blocks[b2][items][0][image]"', $html, 'a media field inside an item');
    assertContains('value="A"', $html, 'the stored value of the first item');

    // Ids carry both indices too, or two items would share one and every label would point
    // at the first of them.
    assertContains('id="block-b2-items-0-heading"', $html, 'the first item\'s id');
    assertContains('id="block-b2-items-1-heading"', $html, 'the second item\'s id');
    assertContains('for="block-b2-items-1-heading"', $html, 'the label follows the id');

    // What repeater.js binds to.
    assertContains('data-repeater="items"', $html, 'the field name for the script');
    assertContains('data-repeater-type="cards"', $html, 'the block type, for finding the item template');
    assertContains('data-repeater-max="3"', $html, 'the maximum');
    // The closing angle bracket matters: 'data-repeater-item' alone is a substring of
    // 'data-repeater-items', the container, so without it this passes for a repeater that
    // rendered no items at all.
    assertContains('data-repeater-item>', $html, 'an item is marked');
    assertEquals(2, substr_count($html, 'class="repeater-item"'), 'items rendered');
});

test('every repeater control works without JavaScript', function (): void {
    $registry = repeaterRegistry();
    $html = renderRepeaterBlock($registry, ['items' => [['heading' => 'A']]]);

    // D-011's pattern one level down: ONE route serves both paths. Add, Move up and Move
    // down are real submits the save route understands, and removal is the same
    // no-js-only checkbox a block uses. A control that only works with scripts would make
    // the plain editor — the thing you reach for when the visual one will not load —
    // unable to edit a Columns block at all.
    assertContains('value="item-add-n0-items"', $html, 'Add is not a submit');
    assertContains('value="item-up-n0-items-0"', $html, 'Move up is not a submit');
    assertContains('value="item-down-n0-items-0"', $html, 'Move down is not a submit');
    assertContains('name="blocks[n0][items][0][_delete]"', $html, 'the no-JavaScript removal');

    // The Add button must NOT be js-only: that was the first draft, and it left a browser
    // without scripts able to remove and reorder items but never to make one.
    assertTrue(!preg_match('~<button[^>]*js-only[^>]*value="item-add~', $html), 'Add is script-only');
});

test('an empty repeater says so rather than showing nothing', function (): void {
    $registry = repeaterRegistry();
    $html = renderRepeaterBlock($registry, []);

    assertContains(e(t('pages.field.repeater_empty')), $html, 'the empty state');
    assertEquals(0, substr_count($html, 'class="repeater-item"'), 'an item was rendered for an empty list');
    assertContains('value="item-add-n0-items"', $html, 'the empty state offers no way to add one');
});

test('a repeater error is shown once, under the group', function (): void {
    $registry = repeaterRegistry(2);
    $message = t('pages.field.repeater_max', ['max' => 2]);
    $html = renderRepeaterBlock($registry, ['items' => [['heading' => 'A']]], 'n0', ['n0.items' => $message]);

    assertContains(e($message), $html, 'the refusal');
    assertEquals(1, substr_count($html, 'class="field-error"'), 'the message is repeated per item');
});

// guard (source, not behaviour): the item template is what an editor clones to add an
// item, and it must exist in BOTH editors. This runner has no browser, so it stands over
// what they are built from — the same standard as the picker guard in editor_test.php.
test('guard (source, not behaviour): both editors emit the item template', function (): void {
    foreach ([
        'app/Modules/Pages/views/admin/edit.php',
        'app/Modules/Pages/views/admin/builder.php',
    ] as $template) {
        $source = (string) file_get_contents(dirname(__DIR__) . '/' . $template);
        assertContains('data-item-template', $source, "{$template} emits no item template");
        assertContains("'__ITEM__'", $source, "{$template} bakes in an item index");
        assertContains("'__INDEX__'", $source, "{$template} bakes in a block index");
    }

    // The block index in an item template is a PLACEHOLDER even for a block whose position
    // is known, because a <template>'s contents are not live nodes and neither editor's
    // renumber() reaches inside one. A template carrying a real index would add items to
    // the wrong block after the first move.
    $item = (string) file_get_contents(dirname(__DIR__) . '/app/Modules/Pages/views/admin/item.php');
    assertContains('$blockIndex', $item, 'the item no longer takes the block index as a parameter');
});

// guard (source, not behaviour): this runner has no browser, so it stands over what the
// editors are BUILT from — the same standard as the picker guard in editor_test.php.
test('guard (source, not behaviour): the two indices are rewritten independently', function (): void {
    $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/repeater.js');

    // The item index sits in the MIDDLE of blocks[n][items][m][field], so this file cannot
    // use the anchored replace both editors use for the block index. If someone simplifies
    // it back to one, moving an item would rewrite the block's position instead.
    assertContains('data-repeater', $js, 'the repeater is no longer found by its field name');
    assertContains('__ITEM__', $js, 'the item placeholder');
    assertContains('__INDEX__', $js, 'the block placeholder');
    assertContains('data-item-template', $js, 'the item template is no longer looked up');

    // A new item arrives as markup: its rich text field is a textarea and its picture field
    // a plain select until both are scanned. Losing either line is silent — the field still
    // posts, it just stops being the control every other field shows.
    assertContains('boxletRichText', $js, 'a new item never becomes a rich text editor');
    assertContains('boxletPicker', $js, 'a new item never becomes a picture picker');

    // One bubbling change, which admin.js reads as "dirty" and builder-blocks.js as
    // "redraw this block". Adding an item is a change to the block's content.
    assertContains('bubbles: true', $js, 'adding an item tells nothing that the page changed');

    // Both editors render a repeater, so both must load the file that drives it.
    foreach (['PageEditorController.php', 'PageBuilderController.php'] as $controller) {
        $source = (string) file_get_contents(dirname(__DIR__) . '/app/Modules/Pages/' . $controller);
        assertContains("'repeater.js'", $source, "{$controller} does not load repeater.js");
    }
});

// The picker had no way to be run over markup that arrived after load, so a block inserted
// into the canvas kept a bare select where every other block shows a picker. Fixed beside
// the repeater because an item's picture field sits directly on it (CLAUDE.md: a weak
// feature is fixed before anything is built on top of it).
test('guard (source, not behaviour): a picker can be raised on markup that arrived later', function (): void {
    $picker = (string) file_get_contents(dirname(__DIR__) . '/public/assets/media-picker.js');
    assertContains('window.boxletPicker', $picker, 'the picker exports no way to scan new markup');
    // Idempotent, or a duplicated block would get two buttons in front of one field.
    assertContains('data-picker-ready', $picker, 'upgrading twice is no longer refused');

    $builder = (string) file_get_contents(dirname(__DIR__) . '/public/assets/builder-blocks.js');
    assertContains('boxletPicker.scan', $builder, 'an inserted block never becomes a picker');
    assertContains('unsetPicker', $builder, 'a duplicated block keeps its clone\'s dead picker');
});

test('moving an item swaps it with its neighbour, and only within its own block', function (): void {
    $registry = repeaterRegistry();
    $blocks = [[
        'key' => 'b1',
        'id' => null,
        'type' => 'cards',
        'content' => $registry->normalize('cards', ['items' => [['heading' => 'A'], ['heading' => 'B'], ['heading' => 'C']]]),
        'style' => [],
        'layout' => 'two',
    ]];
    $headings = static function (array $result): array {
        $content = $result[0]['content'];
        if ($content === null) {
            fail('the block lost its content');
        }

        return array_column($content['items'], 'heading');
    };

    assertEquals(['A', 'C', 'B'], $headings(BlockForm::moveItem($registry, $blocks, 'b1', 'items', 1, 'down')), 'moved down');
    assertEquals(['B', 'A', 'C'], $headings(BlockForm::moveItem($registry, $blocks, 'b1', 'items', 1, 'up')), 'moved up');

    // The edges do nothing rather than wrapping around or dropping an item.
    assertEquals(['A', 'B', 'C'], $headings(BlockForm::moveItem($registry, $blocks, 'b1', 'items', 0, 'up')), 'the first moved up');
    assertEquals(['A', 'B', 'C'], $headings(BlockForm::moveItem($registry, $blocks, 'b1', 'items', 2, 'down')), 'the last moved down');
    assertEquals(['A', 'B', 'C'], $headings(BlockForm::moveItem($registry, $blocks, 'b1', 'items', 9, 'up')), 'an item that is not there');
});

// The action arrives from a form, so the field name in it is somebody's input. It must be
// checked against what the block DECLARES before it indexes stored content — a posted name
// is not a key until the registry agrees it is one.
test('an action naming a field the block does not declare moves nothing', function (): void {
    $registry = repeaterRegistry();
    $content = $registry->normalize('cards', ['items' => [['heading' => 'A'], ['heading' => 'B']]]);
    $blocks = [['key' => 'b1', 'id' => null, 'type' => 'cards', 'content' => $content, 'style' => [], 'layout' => 'two']];

    assertEquals($blocks, BlockForm::moveItem($registry, $blocks, 'b1', 'nonsense', 0, 'down'), 'an unknown field name');
    assertEquals($blocks, BlockForm::addItem($registry, $blocks, 'b1', 'nonsense'), 'an unknown field name, adding');
    assertEquals($blocks, BlockForm::moveItem($registry, $blocks, 'b7', 'items', 0, 'down'), 'a block that is not there');
    assertEquals($blocks, BlockForm::addItem($registry, $blocks, 'b7', 'items'), 'a block that is not there, adding');
});

test('adding an item appends an empty one, and stops at the maximum', function (): void {
    $registry = repeaterRegistry(2);
    $blocks = [[
        'key' => 'b1',
        'id' => null,
        'type' => 'cards',
        'content' => $registry->normalize('cards', ['items' => [['heading' => 'A']]]),
        'style' => [],
        'layout' => 'two',
    ]];

    $added = BlockForm::addItem($registry, $blocks, 'b1', 'items');
    $content = $added[0]['content'];
    if ($content === null) {
        fail('the block lost its content');
    }
    assertEquals(2, count($content['items']), 'items after adding');
    // Every field present at its empty value, the same shape normalize() gives a stored
    // item — so the new item's inputs render like any other rather than missing keys.
    assertEquals(['image' => null, 'heading' => '', 'body' => ''], $content['items'][1], 'the empty item');

    // Full: the button that cannot do anything does nothing, rather than growing the list
    // and handing back a refusal for something the editor itself just did.
    assertEquals($added, BlockForm::addItem($registry, $added, 'b1', 'items'), 'added past the maximum');
});

testBothDrivers('a picture inside an item is resolved for rendering, and cleared when it is gone', function (string $driver): void {
    $db = migratedDatabase($driver);
    $registry = repeaterRegistry();
    $now = gmdate('Y-m-d H:i:s');
    // The column list the other media tests use, not one reassembled from the migration:
    // my first attempt invented `bytes` and `sha1` and left out three NOT NULL columns.
    $db->query(
        'INSERT INTO media (filename, original_name, path, mime, size, width, height, hash, created_at, status, variants_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        ['card', 'card.jpg', 'uploads/card.jpg', 'image/jpeg', 1000, 800, 600, 'hash-card', $now, 'complete',
            '{"thumb":{"formats":["webp"],"width":200,"height":200}}'],
    );
    $real = (int) $db->lastInsertId();
    $gone = $real + 1000;

    $content = $registry->normalize('cards', ['items' => [
        ['image' => $real, 'heading' => 'kept'],
        ['image' => $gone, 'heading' => 'deleted since'],
    ]]);

    // On save: an id naming no picture becomes null, inside an item as at the top level.
    $resolved = MediaReference::resolve($db, $registry, 'cards', $content);
    assertEquals($real, $resolved['items'][0]['image'], 'the real picture survived');
    assertEquals(null, $resolved['items'][1]['image'], 'an id naming no picture was not cleared inside an item');

    // On render: the picture is looked up, so the template is handed it rather than querying.
    $pictures = MediaPicture::forBlocks($db, $registry, 'en', [
        ['type' => 'cards', 'content' => $content, 'style' => []],
    ]);
    assertTrue(isset($pictures[$real]), 'the renderer did not resolve a picture inside an item');
    assertTrue(!isset($pictures[$gone]), 'a missing picture was resolved anyway');
});
