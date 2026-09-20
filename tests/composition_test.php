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
    $section = Presets::COMPOSITION[$preset]['section'];

    return [
        'width' => $section['width'],
        'rhythm' => $section['rhythm'],
        'align' => $section['align'],
        'divider' => Presets::dividerAccent($preset),
        'hero' => Presets::COMPOSITION[$preset]['layouts']['hero'] ?? '',
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
    assertEquals(Composition::style('soft', 'hero')['rhythm'], Composition::style('soft', 'unnamed')['rhythm'], 'unnamed block');
    assertEquals('center', Composition::layout($registry, 'no-such-character', 'hero'), 'a character that does not exist');
    assertEquals(SectionStyle::DEFAULTS, Composition::style(null, 'hero'), 'no character');

    // Whatever a character asks for, a block only ever gets a layout it declares: the
    // guarantee that keeps composing safe when Slice 9 adds six more block types.
    foreach (Presets::names() as $preset) {
        foreach ($registry->types() as $type) {
            $layout = Composition::layout($registry, $preset, $type);
            assertTrue(in_array($layout, $registry->get($type)['layouts'], true), "{$preset} gives {$type} the undeclared layout {$layout}");
            assertEquals(SectionStyle::normalize(Composition::style($preset, $type)), Composition::style($preset, $type), "{$preset}/{$type} section style");
        }
    }
});

testBothDrivers('new blocks are composed by the active character', function (string $driver) {
    $db = adminSite($driver);
    adminPost('/admin/appearance', designFields(Presets::get('brutalist')) + ['character' => 'brutalist', 'action' => 'save']);

    assertEquals('brutalist', Composition::active($db), 'active character');
    adminPost('/admin/pages', ['title' => 'Landing', 'locale' => 'en', 'template' => templateId($db, 'landing')]);
    $blocks = $db->all('SELECT block_type, style_json, layout FROM page_blocks ORDER BY sort');

    foreach ($blocks as $block) {
        $style = json_decode((string) $block['style_json'], true);
        $type = (string) $block['block_type'];
        assertEquals('full', $style['width'] ?? null, "{$type} width");
        assertEquals('tight', $style['rhythm'] ?? null, "{$type} rhythm");
    }
    assertEquals('split', (string) $blocks[0]['layout'], 'hero layout');
});

testBothDrivers('applying a character resets sections only when that is what was asked', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', true, [
        ['type' => 'hero', 'content' => ['heading' => 'Hi'], 'style' => ['surface' => 'contrast', 'rhythm' => 'airy'], 'layout' => 'center'],
    ]);
    $styleOf = static fn (): array => (array) json_decode((string) ($db->one('SELECT style_json FROM page_blocks')['style_json'] ?? ''), true);
    $chosen = $styleOf();

    // Design only: the section keeps what its author chose.
    adminPost('/admin/appearance', designFields(Presets::get('editorial')) + ['character' => 'editorial', 'action' => 'save']);
    assertEquals($chosen, $styleOf(), 'saving the design alone changed a section style');
    assertEquals('editorial', Composition::active($db), 'active character');

    // Design and composition: every section takes the character's shape.
    $response = adminPost('/admin/appearance', designFields(Presets::get('editorial')) + ['character' => 'editorial', 'action' => 'save_composition']);
    assertRedirectedTo('/admin/appearance', $response);
    assertEquals(Composition::style('editorial', 'hero'), $styleOf(), 'the section was not reset');
    assertEquals('left', (string) ($db->one('SELECT layout FROM page_blocks')['layout'] ?? ''), 'the layout was not reset');
    assertContains('rhythm-airy', dispatch('/about')->body, 'the rendered section');
    assertEquals(1, (int) ($db->one('SELECT COUNT(*) AS n FROM pages')['n'] ?? -1), "the page itself survived (id {$id})");
});

test('the choice between design and composition is offered, never taken silently', function () {
    $db = adminSite('sqlite');

    // No blocks yet: nothing to overwrite, so there is one plain Save.
    $empty = adminPost('/admin/appearance', ['action' => 'preset:soft']);
    assertContains('value="save"', $empty->body, 'save button');
    assertTrue(!str_contains($empty->body, 'value="save_composition"'), 'a site with no blocks was offered a reset');

    createPage($db, 'en', 'about', 'About', true, [['type' => 'text', 'content' => ['body' => '<p>x</p>']]]);
    $loaded = adminPost('/admin/appearance', ['action' => 'preset:soft']);
    assertContains('name="character" value="soft"', $loaded->body, 'the loaded character');
    assertContains(e(t('design.apply.design_only')), $loaded->body, 'design-only button');
    assertContains(e(t('design.apply.with_composition')), $loaded->body, 'composition button');
    assertEquals(0, (int) ($db->one('SELECT COUNT(*) AS n FROM design_tokens')['n'] ?? -1), 'loading a character saved something');
});

test('the preview shows the character composition, not only its palette', function () {
    $db = adminSite('sqlite');
    createPage($db, 'en', '', 'Home', true, [['type' => 'hero', 'content' => ['heading' => 'Hi'], 'style' => ['width' => 'narrow'], 'layout' => 'center']]);

    $plain = dispatch('/admin/appearance/preview')->body;
    assertContains('width-narrow', $plain, 'the stored section style');

    $composed = dispatch('/admin/appearance/preview?preset=brutalist&character=brutalist')->body;
    assertContains('width-full', $composed, 'the character measure');
    assertContains('layout-split', $composed, 'the character hero layout');
    assertTrue(!str_contains($composed, 'width-narrow'), 'the stored width survived the composed preview');
});
