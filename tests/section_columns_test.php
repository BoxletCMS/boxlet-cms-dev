<?php

use App\Modules\Design\Composition;
use App\Modules\Design\SectionStyle;
use App\Modules\Pages\Page;
use App\Modules\Pages\SectionLayout;
use App\Modules\Pages\SectionRender;
use App\Modules\Pages\Sections;

/*
 * Columns inside a section (PLAN.md D-093 step 3, D-096, migration 0027).
 *
 * The claim these have to hold up is narrow and total: a section of one column holding one
 * block draws the markup it has always drawn, character for character, and everything else
 * is new shape that no existing page can reach.
 *
 * adminSite() comes from pages_admin_test.php; blockRegistry(), createPage() and
 * blockIdsInOrder() from fixtures.php.
 */

/**
 * A section as SectionRender::draw() wants one.
 *
 * @param array<string, string|int|null> $style
 * @return array{sort: int, layout: string, stack: string, style: array<string, string|int|null>}
 */
function aSection(string $layout = 'one', string $stack = 'stack', array $style = []): array
{
    return ['sort' => 0, 'layout' => $layout, 'stack' => $stack, 'style' => SectionStyle::normalize($style)];
}

/**
 * A block as SectionRender::draw() wants one.
 *
 * @return array{type: string, content: array<mixed>, layout: string, column: int}
 */
function aBlock(string $type, int $column = 0, string $heading = 'Hi'): array
{
    $content = $type === 'text' ? ['body' => '<p>' . $heading . '</p>'] : ['heading' => $heading];

    return ['type' => $type, 'content' => $content, 'layout' => '', 'column' => $column];
}

test('one column holding one block draws exactly what a block has always drawn', function () {
    $registry = blockRegistry();
    $style = SectionStyle::normalize(['surface' => 'tinted', 'rhythm' => 'airy']);
    $block = aBlock('hero', 0, 'Unchanged');

    // The comparison is against the renderer itself, not against a string written out here:
    // an expectation typed by hand proves that the output matches what I believed on the day
    // I typed it, and this has to prove it matches what the site actually did.
    $before = $registry->render('hero', $block['content'], $style, '', [], true, 'section', [], 'en');
    $after = SectionRender::draw($registry, aSection('one', 'stack', $style), [$block], [], true, [], 'en');

    assertEquals($before, $after, 'the one-block section is the block');
    assertTrue(!str_contains($after, 'section-cols'), 'a column container appeared where there is one column');
});

test('a second block in the section gives every block a wrapper and nothing else', function () {
    $registry = blockRegistry();
    $html = SectionRender::draw(
        $registry,
        aSection('halves', 'stack', ['surface' => 'tinted']),
        [aBlock('hero', 0, 'Left'), aBlock('text', 1, 'Right')],
        [],
        true,
        [],
        'en',
    );

    assertTrue(str_contains($html, '<section class="block surface-tinted'), 'the section is the band');
    assertTrue(str_contains($html, 'section-cols cols-halves stack-stack'), 'the column container');
    assertEquals(2, substr_count($html, '<div class="section-column">'), 'columns drawn');
    // The block wears its own layer 3 and NOT .block, which is section language: wearing it
    // would give every block in a column a second band of padding and make the divider
    // rules count blocks where they mean sections.
    assertTrue(str_contains($html, '<div class="block-hero layout-'), 'the block wrapper');
    assertTrue(!str_contains($html, '"block block-hero'), 'a block in a column wears the section class');
});

test('an empty column is still drawn, and a block past the end lands in the last one', function () {
    $registry = blockRegistry();
    // One block in a section shaped for three: the other two are a shape somebody chose and
    // the editor has to have somewhere to drop the next block.
    $thirds = SectionRender::draw($registry, aSection('thirds'), [aBlock('hero', 1)], [], false, [], 'en');
    assertEquals(3, substr_count($thirds, '<div class="section-column">'), 'three columns');
    assertEquals(1, substr_count($thirds, 'block-hero'), 'the block drawn once');

    // A section narrowed from four columns to two still holds blocks remembering column 3.
    // They are drawn in the last column rather than dropped; the stored value is untouched,
    // so widening the section again puts them back.
    $narrowed = SectionRender::draw(
        $registry,
        aSection('halves'),
        [aBlock('hero', 0, 'A'), aBlock('text', 3, 'B')],
        [],
        false,
        [],
        'en',
    );
    assertEquals(2, substr_count($narrowed, '<div class="section-column">'), 'two columns');
    assertTrue(str_contains($narrowed, 'B'), 'the block past the end vanished');
    assertEquals(3, SectionLayout::clamp(3, 'quarters'), 'a column its section has');
    assertEquals(1, SectionLayout::clamp(3, 'halves'), 'clamped to the last column');
    assertEquals(0, SectionLayout::clamp(-2, 'thirds'), 'clamped to the first');
});

test('the layout and the stack are closed sets', function () {
    assertEquals('one', SectionLayout::normalize('sidebar-left'), 'an invented layout');
    assertEquals('one', SectionLayout::normalize(null), 'no layout');
    assertEquals('sidebar', SectionLayout::normalize('sidebar'), 'a real one');
    assertEquals('stack', SectionLayout::normalizeStack('slide'), 'an invented stack');
    assertEquals('reverse', SectionLayout::normalizeStack('reverse'), 'a real one');
    assertEquals(1, SectionLayout::columns('nonsense'), 'an unknown layout holds one column');
    assertEquals(4, SectionLayout::columns('quarters'), 'quarters');
});

test('guard (two declarations of one fact): the stylesheet agrees with SectionLayout', function () {
    // The proportions are written twice — as weights in PHP and as grid tracks in CSS —
    // because CSS is not generated from PHP here. That is only safe while something reads
    // both, so this does. It is the guard, not a style check: what it enforces is that
    // every layout has a rule and that the rule lays out as many tracks as PHP promises.
    $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/sections.css');

    $declared = [];
    foreach (SectionLayout::LAYOUTS as $name => $weights) {
        $found = preg_match('~\.cols-' . preg_quote($name, '~') . '\s*\{([^}]*)\}~', $css, $match) === 1
            ? $match[1]
            : fail("sections.css has no rule for the {$name} layout");
        $tracks = preg_match('~grid-template-columns:([^;]+);~', $found, $columns) === 1
            ? $columns[1]
            : fail("the {$name} layout sets no columns");
        // repeat(n, …) says n; a written-out list says as many as it has minmax()es.
        $count = preg_match('~repeat\(\s*(\d+)~', $tracks, $repeat) === 1
            ? (int) $repeat[1]
            : substr_count($tracks, 'minmax(');
        assertEquals(count($weights), $count, "the {$name} layout draws as many columns as it declares");
        $declared[] = $name;
    }

    // And the other direction: a rule for a layout PHP does not know is dead CSS that
    // nothing can ever select, which is how a stylesheet fills up with rules nobody dares
    // delete. The media query narrows .cols-quarters, which is a rule for a known layout.
    preg_match_all('~\.cols-([a-z-]+)~', $css, $all);
    assertEquals([], array_values(array_diff(array_unique($all[1]), $declared)), 'rules for layouts that do not exist');
});

testBothDrivers('a new section is one column that stacks, and a block remembers its column', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', false, [
        ['type' => 'hero', 'content' => ['heading' => 'Hi']],
    ]);
    [$hero] = blockIdsInOrder($db, $id);

    $sections = Sections::forPage($db, $id);
    $section = reset($sections) ?: fail('no section');
    assertEquals('one', $section['layout'], 'the layout a new section has');
    assertEquals('stack', $section['stack'], 'what it does on a phone');

    // Migration 0027 says every block already stands in column 0 rather than moving any.
    $blocks = Page::blocks($db, $id);
    assertEquals(0, $blocks[0]['column'], 'the column a migrated block sits in');
    assertTrue($blocks[0]['section'] > 0, 'the block names its section');
    assertEquals([$hero], array_map(static fn (array $b): int => $b['id'], $blocks), 'the block read back');
});

testBothDrivers('a translation is given the same columns as its source', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', false, [
        ['type' => 'hero', 'content' => ['heading' => 'Hi']],
    ]);
    $sections = Sections::forPage($db, $id);
    $sectionId = (int) array_key_first($sections);
    // Written through the model, not by hand: save() is the only writer and a test that
    // reaches past it proves something about a shape nothing produces.
    Sections::save($db, $id, $sectionId, 0, $sections[$sectionId]['style'], gmdate('Y-m-d H:i:s'), 'wide-left', 'reverse');

    // And the block stands in the second column. Written straight to the row because the
    // editor cannot put it there yet — that is the next step — and a translation losing an
    // arrangement is exactly the defect D-093 warned about, so it is covered before the
    // thing that creates one exists.
    $db->query('UPDATE page_blocks SET column_index = 1 WHERE page_id = ?', [$id]);

    $translation = (int) App\Modules\Pages\Translations::create($db, blockRegistry(), $id, 'hr');
    $copied = Sections::forPage($db, $translation);
    $section = reset($copied) ?: fail('the translation has no section');

    assertEquals('wide-left', $section['layout'], 'the arrangement came with it');
    // Without this the Croatian page stacks where the English one reverses, which is the
    // one responsive decision an owner cannot express any other way.
    assertEquals('reverse', $section['stack'], 'what it does on a phone came with it');
    assertEquals(
        [1],
        array_map(static fn (array $b): int => $b['column'], Page::blocks($db, $translation)),
        'the block was moved to the first column on the way',
    );
});

testBothDrivers('the plain page editor does not collapse a section it was never asked about', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', false, [
        ['type' => 'hero', 'content' => ['heading' => 'Hi']],
    ]);
    $sections = Sections::forPage($db, $id);
    $sectionId = (int) array_key_first($sections);
    Sections::save($db, $id, $sectionId, 0, [], gmdate('Y-m-d H:i:s'), 'thirds', 'stay');

    // A save that says something about the style and nothing about the columns. Page::update
    // is what the page editor calls, and it has never heard of a layout.
    Page::update($db, blockRegistry(), $id, [
        'title' => 'About', 'slug' => 'about', 'parent_id' => null, 'status' => 'draft', 'seo_json' => '{}',
    ], [[
        'key' => 'b' . blockIdsInOrder($db, $id)[0],
        'id' => blockIdsInOrder($db, $id)[0],
        'type' => 'hero',
        'content' => ['heading' => 'Edited'],
        'style' => ['surface' => 'tinted'],
        'layout' => '',
    ]]);

    $after = Sections::forPage($db, $id);
    $section = $after[$sectionId] ?? fail('the section was replaced');
    assertEquals('thirds', $section['layout'], 'the columns survived an edit that never mentioned them');
    assertEquals('stay', $section['stack'], 'and so did what it does on a phone');
    assertEquals('tinted', $section['style']['surface'], 'the style the editor did send');
});

test('a character composes a section from the type its blocks agree on', function () {
    // `soft`, because it is a character that actually says something about a hero — a
    // tinted surface and a curved edge — where `editorial` says nothing and would let this
    // pass whatever the rule did.
    $hero = Composition::style('soft', 'hero');
    assertEquals('tinted', $hero['surface'], 'the character says nothing about a hero');
    assertEquals($hero, Composition::section('soft', ['hero']), 'one block');
    assertEquals($hero, Composition::section('soft', ['hero', 'hero']), 'two of a kind');

    // Blocks that disagree take the character's own section language and nothing laid over
    // it: no surface from one of them and no divider from the other (D-096).
    $mixed = Composition::section('soft', ['hero', 'form']);
    assertEquals(
        SectionStyle::normalize(App\Modules\Design\Presets::COMPOSITION['soft']['section']),
        $mixed,
        'a mixed section is the character speaking about sections',
    );
    assertEquals('plain', $mixed['surface'], 'the hero\'s surface reached the whole section');
    assertEquals('none', $mixed['divider'], 'the hero\'s edge reached the whole section');

    // A character nobody has heard of composes as nothing at all, the rule style() follows.
    assertEquals(SectionStyle::DEFAULTS, Composition::section('invented', ['hero', 'form']), 'an unknown character');
    assertEquals(SectionStyle::DEFAULTS, Composition::section(null, []), 'no character');
});

testBothDrivers('applying a character composes each section once, from what it holds', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', false, [
        ['type' => 'hero', 'content' => ['heading' => 'Hi'], 'style' => ['surface' => 'plain']],
        ['type' => 'form', 'content' => [], 'style' => ['surface' => 'plain']],
    ]);
    $registry = blockRegistry();
    $blocks = blockIdsInOrder($db, $id);

    // Put both blocks in one section, the way the editor will: the second block joins the
    // first one's section, in the second column.
    $row = $db->one('SELECT section_id FROM page_blocks WHERE id = ?', [$blocks[0]]) ?? fail('no block');
    $first = (int) $row['section_id'];
    $db->query('UPDATE page_blocks SET section_id = ?, column_index = 1 WHERE id = ?', [$first, $blocks[1]]);
    Sections::prune($db, $id);
    Sections::save($db, $id, $first, 0, [], gmdate('Y-m-d H:i:s'), 'halves', 'stack');

    $changed = Composition::apply($db, $registry, 'soft');

    $sections = Sections::forPage($db, $id);
    assertEquals(1, count($sections), 'one section holding both');
    assertEquals(
        Composition::section('soft', ['hero', 'form']),
        $sections[$first]['style'],
        'the mixed section composed from the character',
    );
    // Counted in SECTIONS, because that is the word the message uses: "…:count sections
    // were reset". Two blocks standing in one section are one section restyled.
    assertEquals(1, $changed, 'the number the message reports');
    // Layer 3 is still the block's own and is composed per type, however many types stand
    // in the section.
    $layouts = $db->all('SELECT block_type, layout FROM page_blocks WHERE page_id = ? ORDER BY id', [$id]);
    assertEquals(
        [Composition::layout($registry, 'soft', 'hero'), Composition::layout($registry, 'soft', 'form')],
        array_map(static fn (array $r): string => (string) $r['layout'], $layouts),
        'each block took its own type\'s layout',
    );
});

testBothDrivers('a save that names its sections puts two blocks in one, side by side', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', false, [
        ['type' => 'hero', 'content' => ['heading' => 'Left']],
        ['type' => 'form', 'content' => []],
    ]);
    [$hero, $form] = blockIdsInOrder($db, $id);

    // THE SHAPE THE EDITOR WILL SEND. One section named m0, two blocks naming it, the form
    // in the second column. Nothing here reaches past the model: this is Page::update()'s
    // own contract, exercised the way the form will exercise it.
    Page::update($db, blockRegistry(), $id, [
        'title' => 'About', 'slug' => 'about', 'parent_id' => null, 'status' => 'draft', 'seo_json' => '{}',
    ], [
        ['key' => 'b' . $hero, 'id' => $hero, 'type' => 'hero', 'content' => ['heading' => 'Left'], 'style' => [], 'layout' => '', 'section' => 'm0', 'column' => 0],
        ['key' => 'b' . $form, 'id' => $form, 'type' => 'form', 'content' => [], 'style' => [], 'layout' => '', 'section' => 'm0', 'column' => 1],
    ], [
        ['key' => 'm0', 'id' => null, 'layout' => 'halves', 'stack' => 'reverse', 'style' => ['surface' => 'tinted']],
    ]);

    $sections = Sections::forPage($db, $id);
    assertEquals(1, count($sections), 'the two blocks stand in one section');
    $section = reset($sections) ?: fail('no section');
    assertEquals('halves', $section['layout'], 'the arrangement the save asked for');
    assertEquals('reverse', $section['stack'], 'and what it does on a phone');
    assertEquals('tinted', $section['style']['surface'], 'and the style, once, for both blocks');

    $blocks = Page::blocks($db, $id);
    assertEquals([0, 1], array_map(static fn (array $b): int => $b['column'], $blocks), 'the columns they landed in');
    assertEquals([$hero, $form], array_map(static fn (array $b): int => $b['id'], $blocks), 'both blocks kept, in order');
    // Its place DOWN its column, which is 0 for both because neither has anything above it.
    assertEquals(
        [0, 0],
        array_map(static fn (array $r): int => (int) $r['sort'], $db->all('SELECT sort FROM page_blocks WHERE page_id = ? ORDER BY column_index', [$id])),
        'the place each block has down its own column',
    );

    // And the page draws them as columns, which is the point of all of it.
    $html = SectionRender::draw(blockRegistry(), $section, array_map(
        static fn (array $b): array => ['type' => $b['type'], 'content' => $b['content'], 'layout' => $b['layout'], 'column' => $b['column']],
        $blocks,
    ), [], true, [], 'en');
    assertTrue(str_contains($html, 'cols-halves stack-reverse'), 'the section drew its columns');
});

testBothDrivers('two blocks in one column keep the order they were sent in', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', false, [
        ['type' => 'hero', 'content' => ['heading' => 'One']],
        ['type' => 'text', 'content' => ['body' => '<p>Two</p>']],
    ]);
    [$hero, $text] = blockIdsInOrder($db, $id);

    // Both in column 0 of one section, the text ABOVE the hero — a reordering that the flat
    // editor expressed as page order and that now has to be expressed inside a column.
    Page::update($db, blockRegistry(), $id, [
        'title' => 'About', 'slug' => 'about', 'parent_id' => null, 'status' => 'draft', 'seo_json' => '{}',
    ], [
        ['key' => 'b' . $text, 'id' => $text, 'type' => 'text', 'content' => ['body' => '<p>Two</p>'], 'style' => [], 'layout' => '', 'section' => 'm0', 'column' => 0],
        ['key' => 'b' . $hero, 'id' => $hero, 'type' => 'hero', 'content' => ['heading' => 'One'], 'style' => [], 'layout' => '', 'section' => 'm0', 'column' => 0],
    ], [
        ['key' => 'm0', 'id' => null, 'layout' => 'one', 'stack' => 'stack', 'style' => []],
    ]);

    assertEquals(
        [$text, $hero],
        array_map(static fn (array $b): int => $b['id'], Page::blocks($db, $id)),
        'the order the save sent them in',
    );
    assertEquals(
        [0, 1],
        array_map(static fn (array $r): int => (int) $r['sort'], $db->all('SELECT sort FROM page_blocks WHERE page_id = ? ORDER BY sort', [$id])),
        'their places down the one column',
    );
});

testBothDrivers('a block naming a section nobody sent is given one of its own, not dropped', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', false, [
        ['type' => 'hero', 'content' => ['heading' => 'Kept']],
    ]);
    [$hero] = blockIdsInOrder($db, $id);

    Page::update($db, blockRegistry(), $id, [
        'title' => 'About', 'slug' => 'about', 'parent_id' => null, 'status' => 'draft', 'seo_json' => '{}',
    ], [
        ['key' => 'b' . $hero, 'id' => $hero, 'type' => 'hero', 'content' => ['heading' => 'Kept'], 'style' => [], 'layout' => '', 'section' => 'm9', 'column' => 0],
    ], [
        ['key' => 'm0', 'id' => null, 'layout' => 'halves', 'stack' => 'stack', 'style' => []],
    ]);

    // The block survives. An empty section does not: prune() takes the halves section the
    // save asked for, because nothing ended up standing in it.
    assertEquals([$hero], array_map(static fn (array $b): int => $b['id'], Page::blocks($db, $id)), 'the block was dropped');
    assertEquals(1, count(Sections::forPage($db, $id)), 'sections left standing');
});

testBothDrivers('a block whose type is gone keeps its section style through a save', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', false, [
        ['type' => 'hero', 'content' => ['heading' => 'Hi'], 'style' => ['surface' => 'contrast', 'rhythm' => 'airy']],
    ]);
    [$hero] = blockIdsInOrder($db, $id);
    $db->query('UPDATE page_blocks SET block_type = ? WHERE id = ?', ['gone_away', $hero]);

    // What the editor sends for a block it cannot draw: a null content, no style — it was
    // never rendered a field for one. Its stored style is the only record of what it was.
    Page::update($db, blockRegistry(), $id, [
        'title' => 'About', 'slug' => 'about', 'parent_id' => null, 'status' => 'draft', 'seo_json' => '{}',
    ], [
        ['key' => 'b' . $hero, 'id' => $hero, 'type' => 'gone_away', 'content' => null, 'style' => [], 'layout' => ''],
    ]);

    $style = sectionStyleOf($db, $hero);
    assertEquals('contrast', $style['surface'] ?? null, 'the surface of a block nobody can draw');
    assertEquals('airy', $style['rhythm'] ?? null, 'and its rhythm');
});

testBothDrivers('the form says where a block stands, and a save keeps the section it names', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', false, [
        ['type' => 'hero', 'content' => ['heading' => 'Hi'], 'style' => ['surface' => 'tinted']],
    ]);
    [$hero] = blockIdsInOrder($db, $id);
    $sectionId = (int) array_key_first(Sections::forPage($db, $id));

    // EXACTLY WHAT THE EDITOR POSTS (D-098): the block says which section and column, the
    // section says its own arrangement and style under a prefix of its own.
    $parsed = App\Modules\Pages\BlockForm::parse(blockRegistry(), [
        'b' . $hero => ['id' => (string) $hero, 'type' => 'hero', 'heading' => 'Hi', 'section' => 's' . $sectionId, 'column' => '0'],
    ], [$hero => Page::editable($db, blockRegistry(), $id)[0]]);
    $sections = App\Modules\Pages\SectionForm::parse([
        's' . $sectionId => ['id' => (string) $sectionId, 'layout' => 'thirds', 'stack' => 'reverse', 'style' => ['surface' => 'contrast']],
    ], [$sectionId => $sectionId]);

    assertEquals('s' . $sectionId, $parsed['blocks'][0]['section'] ?? null, 'the block says where it stands');
    assertEquals(0, $parsed['blocks'][0]['column'] ?? null, 'and which column');

    Page::update($db, blockRegistry(), $id, [
        'title' => 'About', 'slug' => 'about', 'parent_id' => null, 'status' => 'draft', 'seo_json' => '{}',
    ], $parsed['blocks'], $sections);

    // THE SAME SECTION ROW, not a new one beside it. A save that replaced it would take its
    // translations, its revisions and its background picture with it — and would be invisible
    // until somebody looked at the ids, which is how it was found.
    $after = Sections::forPage($db, $id);
    assertEquals([$sectionId], array_keys($after), 'the section row the save wrote to');
    assertEquals('thirds', $after[$sectionId]['layout'], 'the arrangement the form chose');
    assertEquals('reverse', $after[$sectionId]['stack'], 'and what it does on a phone');
    assertEquals('contrast', $after[$sectionId]['style']['surface'], 'and the style, from the section prefix');
});

test('a section key is a closed shape, like a block key', function () {
    assertEquals('s42', App\Modules\Pages\SectionForm::key(42, 0), 'a stored section');
    assertEquals('m3', App\Modules\Pages\SectionForm::key(null, 3), 'one made in this session');

    // A key that is not this page's makes a section of its own rather than writing over a
    // stranger's — the rule BlockForm::parse follows for a block id.
    $stray = App\Modules\Pages\SectionForm::parse(['s99' => ['id' => '99', 'layout' => 'halves']], []);
    assertEquals(null, $stray[0]['id'], 'a section id nobody owns was believed');
    assertEquals('halves', $stray[0]['layout'], 'what it asked for');

    // Silence about the arrangement is not "one column" (D-098).
    $quiet = App\Modules\Pages\SectionForm::parse(['s1' => ['id' => '1', 'style' => ['surface' => 'tinted']]], [1 => 1]);
    assertEquals(null, $quiet[0]['layout'], 'silence was heard as a layout');
    assertEquals(null, $quiet[0]['stack'], 'silence was heard as a stack');
    assertEquals('tinted', $quiet[0]['style']['surface'] ?? null, 'the style it did send');

    // And a shape that is not a key at all is named rather than trusted.
    $odd = App\Modules\Pages\SectionForm::parse(['<script>' => ['layout' => 'one']], []);
    assertEquals('m0', $odd[0]['key'], 'a key nobody should have sent was echoed back');
});
