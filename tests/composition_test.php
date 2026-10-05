<?php

use App\Core\Blocks;
use App\Modules\Design\Composition;
use App\Modules\Design\Presets;
use App\Modules\Design\SectionStyle;

// Layer 0 reaching layers 2 and 3: a character sets how a page is composed, not only
// how it is painted (SPEC §5.4).

/**
 * @return array<string, string> the shape of a character, as the page reads it
 */
function shapeOf(string $preset): array
{
    $section = App\Modules\Design\Characters::composition($preset)['section'];

    return [
        'width' => $section['width'],
        // The space between sections is a decision since D-164, the character's section gap.
        'gap' => App\Modules\Design\Characters::decisions($preset)['section_gap'],
        'align' => $section['align'],
        'divider' => Presets::dividerAccent($preset),
        'hero' => App\Modules\Design\Characters::composition($preset)['layouts']['hero'] ?? '',
    ];
}

test('every character composes a different shape', function () {
    $shapes = [];
    foreach (Presets::names() as $preset) {
        $shapes[$preset] = shapeOf($preset);
    }

    foreach ($shapes as $a => $first) {
        foreach ($shapes as $b => $second) {
            if ($a >= $b) {
                continue;
            }
            $different = count(array_filter(array_keys($first), static fn (string $k): bool => $first[$k] !== $second[$k]));
            assertTrue($different >= 2, "{$a} and {$b} compose the page the same way (" . $different . ' of 5 dimensions differ)');
        }
    }
});

// A divider marks a transition. Drawn on every boundary it stops reading as one, and
// the page becomes a stack of lozenges rather than a composition.
test('a divider is an accent, never a default for every section', function () {
    $types = Blocks::discover(dirname(__DIR__) . '/app/Blocks')->types();

    foreach (Presets::names() as $preset) {
        $drawn = 0;
        foreach ($types as $type) {
            if (Composition::style($preset, $type)['divider'] !== 'none') {
                $drawn++;
            }
        }
        assertTrue($drawn < count($types), "{$preset} draws a divider on every block type");
    }

    assertEquals('curve', Composition::style('soft', 'hero')['divider'], 'soft draws its accent');
    assertEquals('none', Composition::style('soft', 'image_text')['divider'], 'soft elsewhere');
    assertEquals('curve', Presets::dividerAccent('soft'), 'the shape soft uses');
    assertEquals('none', Presets::dividerAccent('brutalist'), 'brutalist draws no edges at all');
});

test('the first section on a page never draws a divider', function () {
    // Browser behaviour, so what is testable here is that the rule exists and covers
    // both the rule and the shaped edges.
    $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/sections.css');
    $rule = strstr($css, 'main > .block:first-child') ?: fail('sections.css has no first-section rule');
    $rule = substr($rule, 0, (int) strpos($rule, '}'));

    foreach (['border-top: 0', 'clip-path: none', 'border-start-start-radius: 0'] as $needed) {
        assertContains($needed, $rule, 'the first-section rule');
    }
});

test('a character composes every block type, including ones it never names', function () {
    $registry = Blocks::discover(dirname(__DIR__) . '/app/Blocks');

    assertEquals('normal', Composition::style('editorial', 'text')['width'], 'editorial measure');
    assertEquals('full', Composition::style('brutalist', 'text')['width'], 'brutalist measure');
    assertEquals('gradient', Composition::style('bold', 'hero')['surface'], 'bold hero surface');
    assertEquals('left', Composition::layout($registry, 'editorial', 'hero'), 'editorial hero');
    assertEquals('split', Composition::layout($registry, 'soft', 'hero'), 'soft hero');

    // A type the character never names still gets its section style and a valid layout.
    assertEquals(Composition::style('soft', 'hero')['width'], Composition::style('soft', 'unnamed')['width'], 'unnamed block');
    assertEquals(App\Modules\Design\Characters::composition('soft')['section']['surface'], Composition::style('soft', 'unnamed')['surface'], 'and its surface is the section\'s own');
    assertEquals('center', Composition::layout($registry, 'no-such-character', 'hero'), 'a character that does not exist');
    assertEquals(SectionStyle::DEFAULTS, Composition::style(null, 'hero'), 'no character');

    // Whatever a character asks for, a block only ever gets a layout it declares: the
    // guarantee that keeps composing safe when Slice 9 adds six more block types.
    foreach (Presets::names() as $preset) {
        foreach ($registry->types() as $type) {
            $layout = Composition::layout($registry, $preset, $type);
            assertTrue(in_array($layout, $registry->get($type)['layouts'], true), "{$preset} gives {$type} the undeclared layout {$layout}");
            // Every composed key answered, each a value it may hold (the padding '' being the
            // section gap): a composition is never itself a source of a refused value.
            $composed = Composition::style($preset, $type);
            assertEquals(array_keys(SectionStyle::DEFAULTS), array_keys($composed), "{$preset}/{$type} section style keys");
            assertEquals($composed, array_intersect_key(SectionStyle::normalize($composed), SectionStyle::DEFAULTS), "{$preset}/{$type} section style");
        }
    }
});

// THE RULE CHANGED DELIBERATELY with D-165 (O-41): a new block's section used to be given the
// active character's style, copied in, so a later character re-dressed nothing the owner had
// not asked it to. Now it stores nothing and is drawn as whatever character the site has.
testBothDrivers('a new section stores nothing, and is drawn as the character the site has now', function (string $driver) {
    $db = adminSite($driver);
    adminPost('/admin/appearance', designFields(Presets::get('brutalist')) + ['character' => 'brutalist', 'action' => 'save']);

    assertEquals('brutalist', Composition::active($db), 'active character');
    adminPost('/admin/pages', ['title' => 'Landing', 'locale' => 'en', 'template' => templateId($db, 'landing')]);
    $blocks = blocksWithStyle($db);
    $page = (int) ($db->one('SELECT id FROM pages WHERE title = ?', ['Landing'])['id'] ?? 0);
    $db->query('UPDATE pages SET status = ? WHERE id = ?', ['published', $page]);
    $slug = (string) ($db->one('SELECT slug FROM pages WHERE id = ?', [$page])['slug'] ?? '');

    foreach ($blocks as $block) {
        assertTrue(!SectionStyle::overridden(SectionStyle::normalize($block['style'])), "{$block['type']} stored a style");
    }
    // CHANGED DELIBERATELY with D-191: a new block stores '' and follows the character.
    assertEquals('', (string) $blocks[0]['layout'], 'hero layout, following');
    assertContains('width-full', dispatch('/' . $slug)->body, 'drawn as Brutalist');
    assertContains('layout-split', dispatch('/' . $slug)->body, 'in Brutalist\'s hero layout');

    // Another character, published as design only: the sections nobody touched follow it.
    Composition::remember($db, 'editorial');
    $body = dispatch('/' . $slug)->body;
    assertTrue(!str_contains($body, 'width-full'), 'still drawn as Brutalist');
    assertContains('width-normal', $body, 'drawn as Editorial');
});

testBothDrivers('applying a character hands back what was set by hand only when that is what was asked', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', true, [
        ['type' => 'hero', 'content' => ['heading' => 'Hi'], 'style' => ['surface' => 'contrast', 'pad_top' => '120', 'anchor' => 'top', 'hide_mobile' => 'yes'], 'layout' => 'center'],
    ]);
    // The style is the SECTION's since D-095; the page has exactly one block.
    $styleOf = static fn (): array => blocksWithStyle($db, $id)[0]['style'] ?? [];
    $chosen = $styleOf();
    assertEquals(1, Composition::styledByHand($db), 'one section styled by hand');

    // Design only: the section keeps what its author chose. Publish ASKS first on a site
    // that has blocks (D-068), and this is the answer that leaves them alone. The question
    // says how many sections it would hand back (D-165).
    $asked = adminPost('/admin/appearance', designFields(Presets::get('editorial')) + ['character' => 'editorial', 'action' => 'save']);
    assertEquals(200, $asked->status, 'Publish asked rather than applying');
    assertContains('One section you styled by hand goes back to the character.', $asked->body, 'and said what Apply would hand back');
    assertEquals(0, (int) ($db->one('SELECT COUNT(*) AS n FROM design_tokens')['n'] ?? -1), 'and wrote nothing while it asked');
    adminPost('/admin/appearance', designFields(Presets::get('editorial')) + ['character' => 'editorial', 'action' => 'save_design']);
    assertEquals($chosen, $styleOf(), 'saving the design alone changed a section style');
    assertEquals('editorial', Composition::active($db), 'active character');
    assertContains('surface-contrast', dispatch('/about')->body, 'the owner\'s surface, drawn');

    // Design and composition: what the character composes goes back to it; the anchor and
    // the visibility are the owner's content and stay.
    $response = adminPost('/admin/appearance', designFields(Presets::get('editorial')) + ['character' => 'editorial', 'action' => 'save_composition']);
    assertRedirectedTo('/admin/appearance', $response);
    $reset = $styleOf();
    foreach (SectionStyle::composed() as $key) {
        assertEquals('', $reset[$key] ?? null, "{$key} was not handed back");
    }
    assertEquals('top', $reset['anchor'] ?? null, 'the anchor stays');
    assertEquals('yes', $reset['hide_mobile'] ?? null, 'and where it is hidden');
    assertEquals(0, Composition::styledByHand($db), 'nothing left styled by hand');
    // CHANGED DELIBERATELY with D-191 (the owner): a layout the owner chose is theirs, and
    // Apply keeps it; one that follows the character follows it already.
    assertEquals('center', (string) ($db->one('SELECT layout FROM page_blocks')['layout'] ?? ''), 'the owner\'s layout, kept');
    $body = dispatch('/about')->body;
    assertContains(implode(' ', SectionStyle::classes(SectionStyle::effective(SectionStyle::normalize($reset), Composition::style('editorial', 'hero')))), $body, 'the rendered section, as Editorial composes it');
    assertContains('id="top"', $body, 'still answering to its anchor');
    assertEquals(1, (int) ($db->one('SELECT COUNT(*) AS n FROM pages')['n'] ?? -1), "the page itself survived (id {$id})");
});

test('the choice between design and composition is asked at Publish, never taken silently', function () {
    $db = adminSite('sqlite');

    // No blocks yet: nothing to overwrite, so Publish simply publishes (D-068).
    $empty = adminPost('/admin/appearance', appearanceFields(['character' => 'soft', 'action' => 'save']));
    assertRedirectedTo('/admin/appearance', $empty);

    // With blocks, the same press asks, and the bar itself never carries the destructive
    // button — a choice that matters on one publish in twenty does not live in the bar.
    createPage($db, 'en', 'about', 'About', true, [['type' => 'text', 'content' => ['body' => '<p>x</p>']]]);
    // Loading a character asks nothing since D-164: the owner's changes are kept over it.
    $loaded = adminPost('/admin/appearance', appearanceFields(['action' => 'preset:soft']));
    assertContains('name="character" value="soft"', $loaded->body, 'the loaded character');
    assertTrue(!str_contains($loaded->body, 'value="save_composition"'), 'the bar offered a reset before anyone asked for one');

    // Measured across the ask, not against zero: the publish above wrote a design, and a
    // test that expects nothing at all would be measuring that instead.
    $stored = static fn (): string => (string) ($db->one('SELECT value_json FROM design_tokens WHERE group_key = ?', ['seed'])['value_json'] ?? '');
    $before = $stored();
    $asked = adminPost('/admin/appearance', appearanceFields(['seed' => '#3b6b4f', 'character' => 'soft', 'action' => 'save']));
    assertEquals(200, $asked->status, 'Publish asked');
    assertContains(e(t('design.apply.design_only')), $asked->body, 'design-only answer');
    assertContains(e(t('design.apply.with_composition')), $asked->body, 'composition answer');
    assertContains(e(t('design.apply.with_composition_hint')), $asked->body, 'and what the second one does');
    assertEquals($before, $stored(), 'asking published something');
});

test('the preview shows the character composition, not only its palette', function () {
    $db = adminSite('sqlite');
    // Following the character (D-191): the preview draws it in the character being tried.
    createPage($db, 'en', '', 'Home', true, [['type' => 'hero', 'content' => ['heading' => 'Hi'], 'style' => ['width' => 'narrow'], 'layout' => '']]);

    $plain = dispatch('/admin/appearance/preview')->body;
    assertContains('width-narrow', $plain, 'the stored section style');

    // THE RULE CHANGED DELIBERATELY with D-165: the owner's own width is theirs under any
    // character, and only what the section left to its character is the character's — so
    // the preview of Brutalist keeps the narrow measure and takes Brutalist's surface.
    $composed = dispatch('/admin/appearance/preview?preset=brutalist&character=brutalist')->body;
    assertContains('width-narrow', $composed, 'the owner\'s measure, kept');
    assertContains('surface-' . App\Modules\Design\Composition::style('brutalist', 'hero')['surface'], $composed, 'the character\'s surface for what was left to it');
    assertContains('layout-split', $composed, 'the character hero layout');
});
