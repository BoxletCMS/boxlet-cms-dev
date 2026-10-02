<?php

use App\Modules\Appearance\AppearanceActions;
use App\Modules\Appearance\LayoutDiagram;
use App\Modules\Appearance\Overrides;
use App\Modules\Design\Characters;
use App\Modules\Design\Palette;
use App\Modules\Design\Presets;
use App\Modules\Design\Tokens;
use App\Modules\Settings\ChromeLook;
use App\Support\Controls;

// The Appearance screen's inspector (PLAN.md D-157 to D-160): sections and groups, what is the
// owner's own over the character, the three resets, the question before a character is
// loaded over changes, the contrast check in a line, and the layout diagram. adminSite() and
// adminPost() from pages_admin_test.php; appearanceFields() from fixtures.php, which posts
// the default character's decisions — a screen with nothing changed.

/** How many times a marker appears in a body. */
function occurrences(string $needle, string $body): int
{
    return substr_count($body, $needle);
}

test('every decision and every look choice is in exactly one group of one section', function () {
    // Every key of one design, the look's included: one store since D-164.
    $expected = array_keys(Tokens::validate(Presets::get(Presets::DEFAULT))['decisions']);
    $placed = Overrides::keys('all') ?? [];
    sort($expected);
    $sorted = $placed;
    sort($sorted);
    assertEquals($expected, $sorted, 'the keys the sections hold');
    assertEquals(count($placed), count(array_unique($placed)), 'a key in two places');
    // The palette group is the palette's hand-set roles, in its order.
    assertEquals(array_map(static fn (string $role): string => 'color_' . $role, Palette::BY_HAND), Overrides::SECTIONS['colours']['palette'], 'the palette roles');
});

testBothDrivers('without a script the screen is one column: the home, its links, and every section under it', function (string $driver) {
    adminSite($driver);
    $body = dispatch('/admin/appearance')->body;

    assertContains('id="appearance-home"', $body, 'the home');
    foreach (array_keys(Overrides::SECTIONS) as $section) {
        assertContains('id="section-' . $section . '" data-view="' . $section . '"', $body, 'the ' . $section . ' section');
        assertContains('href="#section-' . $section . '"', $body, 'the link down to ' . $section);
        assertContains('value="reset:section:' . $section . '"', $body, 'its reset');
    }
    // Nothing is hidden by the server but the mirrors, which are a script's to show.
    assertEquals(1, occurrences('data-view="home"', $body), 'one home');
    assertTrue(!str_contains($body, '<section class="inspector-view inspector-section" id="section-colours" data-view="colours" aria-labelledby="section-colours-title" hidden'), 'a section hidden without a script');
    assertContains('<div class="quick-mirrors" data-quick hidden>', $body, 'the mirrors wait for a script');
    // Every group is a <details>, open but for Fine-tuning.
    assertContains('<details class="control-group" id="group-typography-sizes" data-group="group-typography-sizes" open>', $body, 'a group, open');
    assertContains('<details class="control-group" id="group-typography-fine" data-group="group-typography-fine">', $body, 'Fine-tuning, closed');
    // The old tabs and the rail are gone.
    assertTrue(!str_contains($body, 'data-tab='), 'a tab');
    assertTrue(!str_contains($body, 'appearance-rail'), 'the rail');
    // The admin's CSP: no style attribute anywhere on the screen.
    assertEquals(0, preg_match('~\sstyle="~', $body), 'a style attribute');
});

testBothDrivers('each control is in the form once; Quick start repeats six, owned by a form that is never sent', function (string $driver) {
    adminSite($driver);
    $body = dispatch('/admin/appearance')->body;
    $quick = ['seed', 'mode', 'typography', 'text_size', 'radius', 'spacing'];
    foreach (Overrides::keys('all') ?? [] as $key) {
        assertEquals(in_array($key, $quick, true) ? 2 : 1, occurrences('data-control="' . $key . '"', $body), 'rows for ' . $key);
    }
    assertContains('<form id="appearance-quick" hidden></form>', $body, 'the mirrors\' form');
    assertEquals(1, preg_match('~<input type="range" id="quick-spacing" name="spacing"[^>]* form="appearance-quick">~', $body), 'a slider\'s mirror belongs to it');
    assertContains('id="quick-seed" name="seed" form="appearance-quick"', $body, 'the colour\'s mirror too');
    // And the forms are not nested: the mirrors' form closes before #design-form opens.
    assertTrue(strpos($body, '<form id="appearance-quick"') < (int) strpos($body, 'id="design-form"'), 'the mirrors\' form inside the design form');
});

testBothDrivers('a control the owner changed carries a dot and a reset; the defaults are the character\'s', function (string $driver) {
    adminSite($driver);
    $screen = adminPost('/admin/appearance', appearanceFields(['spacing' => '1.25', 'look_header_arrangement' => 'split', 'action' => 'keep']));
    assertEquals(200, $screen->status, 'status');
    assertContains('class="control-row is-changed" data-control="spacing" data-default="1" data-kind="decision"', $screen->body, 'spacing, changed');
    assertContains('class="control-row" data-control="radius" data-default="4" data-kind="decision"', $screen->body, 'corners, as Minimal has them');
    assertContains('class="control-row is-changed" data-control="header_arrangement" data-default="centred" data-kind="look"', $screen->body, 'the header, changed');
    assertContains('value="reset:spacing"', $screen->body, 'the reset');
    assertContains('2 own changes over Minimal', $screen->body, 'the banner');
    assertContains('data-section-count="space" title="' . e(t('controls.changed_count')) . '">1<', $screen->body, 'the count on Space & shape');
});

testBothDrivers('a look choice equal to the character\'s is stored as following it', function (string $driver) {
    $db = adminSite($driver);
    // No "follow" button any more: the character's answer is the one pressed, and posting it
    // means "as the character has it" (D-159).
    $saved = adminPost('/admin/appearance', appearanceFields(['look_header_arrangement' => 'centred', 'look_nav_style' => 'chips', 'action' => 'save']));
    assertRedirectedTo('/admin/appearance', $saved);
    assertEquals('', ChromeLook::stored($db)['header_arrangement'], 'Minimal\'s own answer, pinned');
    assertEquals('chips', ChromeLook::stored($db)['nav_style'], 'the owner\'s answer');
    // And the screen draws the character's answer pressed, not an empty group.
    assertContains('name="look_header_arrangement" value="centred" checked', dispatch('/admin/appearance')->body, 'the character\'s answer, pressed');
    assertTrue(!str_contains(dispatch('/admin/appearance')->body, 'segment-follow'), 'a follow button');
});

testBothDrivers('reset puts one control, a section or everything back, and publishes nothing', function (string $driver) {
    $db = adminSite($driver);
    $before = $db->all('SELECT * FROM design_tokens ORDER BY group_key');
    $changed = ['spacing' => '1.25', 'radius' => '16', 'container' => '68', 'look_header_arrangement' => 'split', 'color_text_on' => '1', 'color_text' => '#101010'];
    $slider = static fn (string $key, string $value, string $body): bool => preg_match('~<input type="range" id="design-' . $key . '" name="' . $key . '"[^>]* value="' . preg_quote($value, '~') . '"~', $body) === 1;

    // A reset is '' — the character's (D-164); the screen shows Minimal's value again.
    $one = adminPost('/admin/appearance', appearanceFields($changed + ['action' => 'reset:spacing']));
    assertEquals(200, $one->status, 'status');
    assertTrue($slider('spacing', '1', $one->body), 'spacing back');
    assertTrue($slider('radius', '16', $one->body), 'corners kept');

    $section = adminPost('/admin/appearance', appearanceFields($changed + ['action' => 'reset:section:space']));
    assertTrue($slider('spacing', '1', $section->body), 'spacing back');
    assertTrue($slider('radius', '4', $section->body), 'corners back');
    assertTrue($slider('container', '68', $section->body), 'the width, in another section, kept');

    $all = adminPost('/admin/appearance', appearanceFields($changed + ['action' => 'reset:all']));
    assertTrue($slider('container', '56', $all->body), 'the width back');
    assertContains('name="look_header_arrangement" value="centred" checked', $all->body, 'the header back to Minimal\'s');
    assertTrue(!str_contains($all->body, 'name="color_text_on" value="1" checked'), 'the text colour still the owner\'s');
    assertContains(e(t('inspector.reset.all_done')), $all->body, 'what the owner is told');

    assertEquals(404, adminPost('/admin/appearance', appearanceFields(['action' => 'reset:section:nowhere']))->status, 'a section that is none');
    assertEquals(404, adminPost('/admin/appearance', appearanceFields(['action' => 'reset:menu']))->status, 'a key that is none');
    assertEquals($before, $db->all('SELECT * FROM design_tokens ORDER BY group_key'), 'the published design');
});

// D-164, the rebuild's README 1.1: changing the character keeps every value the owner set.
// Before it, a character replaced the decisions and the screen asked first (D-158); with
// nothing replaced there is nothing to ask.
testBothDrivers('loading a character keeps the owner\'s changes, and asks nothing', function (string $driver) {
    $db = adminSite($driver);
    $mine = ['spacing' => '1.25', 'radius' => '16', 'look_nav_style' => 'chips'];

    $loaded = adminPost('/admin/appearance', appearanceFields($mine + ['action' => 'preset:bold']));
    assertEquals(200, $loaded->status, 'status');
    assertContains('name="character" value="bold"', $loaded->body, 'the loaded character');
    assertContains('name="seed" value="' . Presets::get('bold')['seed'] . '"', $loaded->body, 'Bold\'s colour, which the owner had not changed');
    assertEquals(1, preg_match('~id="design-spacing" name="spacing"[^>]* value="1.25"~', $loaded->body), 'the owner\'s spacing kept');
    assertContains('name="look_nav_style" value="chips" checked', $loaded->body, 'and the header choice');
    assertTrue(!str_contains($loaded->body, 'load-confirm'), 'a question');
    assertEquals(0, (int) ($db->one('SELECT COUNT(*) AS n FROM design_tokens')['n'] ?? -1), 'loading published something');
    assertEquals(404, adminPost('/admin/appearance', appearanceFields(['action' => 'preset:nobody']))->status, 'a character that is none');
});

testBothDrivers('Fix automatically frees the colours by hand that fail, and only those', function (string $driver) {
    adminSite($driver);
    // A grey text the owner chose, on a background they also chose: the text fails.
    $fields = appearanceFields([
        'color_text_on' => '1', 'color_text' => '#c8c8c8',
        'color_background_on' => '1', 'color_background' => '#fafafa',
    ]);
    $asked = adminPost('/admin/appearance', $fields + ['action' => 'keep']);
    assertContains('value="colour:free:failing" class="button button-secondary" data-contrast-fix>', $asked->body, 'the button, offered');
    assertContains('data-contrast-seed hidden', $asked->body, 'no word about the main colour');

    $fixed = adminPost('/admin/appearance', $fields + ['action' => 'colour:free:failing']);
    assertTrue(!str_contains($fixed->body, 'name="color_text_on" value="1" checked'), 'the failing text still set by hand');
    assertContains('name="color_background_on" value="1" checked', $fixed->body, 'the background that passed kept');
    assertContains('data-contrast-fails hidden', $fixed->body, 'still failing');
    $decisions = Tokens::resolve(Tokens::validate(App\Modules\Appearance\AppearanceForm::decisions($fields))['decisions']);
    assertEquals('color_text', AppearanceActions::failingByHand($decisions), 'the colour blamed');
});

testBothDrivers('a pair the main colour fails offers no button, and says to change the colour', function (string $driver) {
    adminSite($driver);
    $screen = adminPost('/admin/appearance', appearanceFields(['seed' => '#ffe600', 'action' => 'keep']));
    assertContains('data-contrast-fix hidden', $screen->body, 'a button that could not fix it');
    assertContains('<p class="hint hint-always" data-contrast-seed>', $screen->body, 'the word about the main colour');
    assertContains('<details class="gauge-more" open>', $screen->body, 'the list, open on a failure');
});

test('the layout diagram draws the sheet, the text and the bars from the decisions', function () {
    $minimal = Characters::decisions('minimal');
    $flat = LayoutDiagram::geometry($minimal);
    assertEquals(288, $flat['sheet']['width'], 'an unboxed sheet is the window');
    assertEquals(179, $flat['content']['width'], 'the text at 56rem of a 1440px window');
    assertEquals(288, $flat['header']['width'], 'the bar across the window');

    $boxed = ['boxed' => 'yes', 'sheet_width' => '64', 'header_bleed' => 'full', 'header_width' => 'window', 'footer_bleed' => 'sheet', 'footer_width' => 'sheet'] + $minimal;
    $drawn = LayoutDiagram::geometry($boxed);
    assertEquals(205, $drawn['sheet']['width'], 'a sheet of 64rem');
    assertEquals(288, $drawn['header']['width'], 'a header across the window');
    assertEquals(0, $drawn['header']['y'], 'above the sheet');
    assertEquals(288, $drawn['header-content']['width'], 'its content across it');
    assertEquals(205, $drawn['footer']['width'], 'a footer inside the sheet');
    assertEquals(205, $drawn['footer-content']['width'], 'its content with the sheet');
    assertTrue($drawn['sheet']['y'] > $drawn['header']['y'] + $drawn['header']['height'] - 1, 'the sheet under the bar');
    assertEquals(0, preg_match('~style=~', LayoutDiagram::svg($boxed)), 'a style attribute');
});

testBothDrivers('/check answers the summaries and the diagram while a control moves', function (string $driver) {
    adminSite($driver);
    $query = http_build_query(appearanceFields(['boxed' => 'yes', 'sheet_width' => '64', 'look_header_arrangement' => 'split']));
    $answer = json_decode(dispatch('/admin/appearance/check?' . $query)->body, true);
    assertTrue(is_array($answer), 'not JSON');
    assertEquals(t('inspector.summary.layout_boxed', ['sheet' => 1024, 'content' => 896]), $answer['readouts']['summary.layout'] ?? null, 'the layout\'s line');
    assertContains(t('chrome.look.header_arrangement.split'), (string) ($answer['readouts']['summary.header'] ?? ''), 'the header\'s line');
    assertEquals(205, $answer['diagram']['sheet']['width'] ?? null, 'the sheet, drawn');
});

test('the shared controls know nothing of designs and print what a script reads', function () {
    $row = Controls::row('Gap', '<input>', ['key' => 'gap', 'default' => '8', 'changed' => true, 'reset' => ['form' => 'f', 'name' => 'action', 'value' => 'reset:gap', 'title' => 'Put back'], 'for' => 'gap']);
    assertContains('<div class="control-row is-changed" data-control="gap" data-default="8">', $row, 'the row');
    assertContains('<label class="control-label" for="gap">Gap</label>', $row, 'its name');
    assertContains('form="f" name="action" value="reset:gap"', $row, 'its reset');
    assertTrue(!str_contains(Controls::row('Gap', '<input>'), 'data-control'), 'a row without a key names one');

    $slider = Controls::slider('w', 'w', '56', 36, 88, 2, ['42' => 'Narrow', '56' => 'Normal', '68' => 'Wide', '99' => 'Off the end']);
    assertContains('<input type="range" id="w" name="w" min="36" max="88" step="2" value="56">', $slider, 'the input');
    assertContains('<text x="38.46%" y="11" text-anchor="middle">Normal</text>', $slider, 'a mark where its value is');
    assertTrue(!str_contains($slider, 'Off the end'), 'a mark outside the range');

    // Two marks that would touch: the one nearer the middle of the range is drawn, the other
    // left out, and what is drawn is in the order of its values (D-171). Corners and Spacing,
    // whose words ran together as "SquaSubtle" and "CompactNormal".
    $corners = Controls::slider('r', 'r', '4', 0, 32, 1, [0 => 'Square', 4 => 'Subtle', 12 => 'Round']);
    assertTrue(!str_contains($corners, 'Square'), 'the mark at the end gives way');
    assertTrue(strpos($corners, 'Subtle') < strpos($corners, 'Round'), 'Subtle, then Round');
    $spacing = Controls::slider('s', 's', '1', 0.75, 1.75, 0.05, ['0.875' => 'Compact', '1' => 'Normal', '1.25' => 'Roomy', '1.5' => 'Generous']);
    assertEquals(['Normal', 'Roomy', 'Generous'], preg_match_all('~>(\w+)</text>~', $spacing, $m) > 0 ? $m[1] : [], 'Compact gives way to Normal');
    // Marks with room between them are all drawn, ends included.
    $size = Controls::slider('t', 't', '16', 14, 20, 0.5, ['15' => 'Small', '16' => 'Normal', '17' => 'Large', '18' => 'Larger']);
    assertEquals(4, substr_count($size, '<text '), 'four marks with room');
    assertContains('text-anchor="start">Square</text>', Controls::slider('b', 'b', '6', 0, 28, 1, [0 => 'Square', 28 => 'Pill']), 'an end hangs inward');

    $group = Controls::group('g', 'Group', 'body', ['open' => false]);
    assertContains('<details class="control-group" id="g" data-group="g">', $group, 'closed, and no changes');
    assertContains('<details class="control-group has-changes" id="g" data-group="g" open>', Controls::group('g', 'Group', 'body', ['changed' => 2]), 'open, with its count');
});

test('settle() and changed() read the character the screen is measured against', function () {
    $values = array_fill_keys(array_keys(Characters::decisions('minimal')), '');
    $values['header_arrangement'] = 'centred';
    $values['nav_style'] = 'chips';
    $values['spacing'] = '1';
    $values['radius'] = '16';
    $values['color_link'] = '#123456';
    $settled = Overrides::settle($values, 'minimal');
    assertEquals('', $settled['header_arrangement'], 'Minimal\'s own header');
    assertEquals('', $settled['spacing'], 'Minimal\'s own spacing');
    assertEquals('chips', $settled['nav_style'], 'the owner\'s');
    // A key that follows the pairing settles against the pairing (Modern: 650 → 700 on the
    // slider's steps, a tracking of -0.025em).
    $values['heading_weight'] = '700';
    assertEquals('', Overrides::settle($values, 'minimal')['heading_weight'], 'the pairing\'s weight');
    // In the order of the sections: the palette is in Colours, before Space & shape.
    assertEquals(['color_link', 'radius', 'nav_style'], Overrides::changed($settled, 'minimal'), 'which');
});

// D-161: Publish's question after a character is loaded has a way out. (Its count of what
// the site loses went with D-164: a character no longer replaces the owner's values.)
testBothDrivers('Publish after a character asks, and offers Cancel', function (string $driver) {
    $db = adminSite($driver);
    createPage($db, 'en', '', 'Home', true, [['type' => 'hero', 'content' => ['heading' => 'Welcome']]]);
    $asked = adminPost('/admin/appearance', appearanceFields(['character' => 'bold', 'action' => 'save']));
    assertEquals(200, $asked->status, 'status');
    assertContains(e(t('design.apply.title', ['character' => 'Bold'])), $asked->body, 'the question');
    assertContains('<a class="button button-quiet" href="/admin/appearance" data-apply-cancel>' . e(t('inspector.apply.cancel')) . '</a>', $asked->body, 'the way out');
    assertEquals(0, (int) ($db->one('SELECT COUNT(*) AS n FROM design_tokens')['n'] ?? -1), 'asking published something');
});

testBothDrivers('the header and footer arrangements are tiles with a drawing each', function (string $driver) {
    adminSite($driver);
    $body = dispatch('/admin/appearance')->body;
    foreach (['header_arrangement', 'footer_layout'] as $choice) {
        $start = (int) strpos($body, '<div class="tile-choice" role="radiogroup" aria-labelledby="look_' . $choice . '-label">');
        assertTrue($start > 0, 'the ' . $choice . ' tiles');
        $group = substr($body, $start, (int) strpos($body, '</div>', $start) - $start);
        assertEquals(count(choicesOf($choice)), substr_count($group, '<label class="tile-option">'), 'a tile for every answer');
        assertEquals(count(choicesOf($choice)), substr_count($group, '<svg viewBox="0 0 48 24"'), 'a drawing on each');
    }
    // Minimal's own arrangement, pressed.
    assertContains('name="look_header_arrangement" value="centred" checked', $body, 'the character\'s answer');
});

test('the hints are an icon whose words are its label, not a row of their own', function () {
    adminSite('sqlite');
    $body = dispatch('/admin/appearance')->body;
    assertContains('class="icon-button hints-icon" data-hints-toggle hidden', $body, 'the icon');
    assertContains('data-hints-label>' . e(t('hints.show')) . '</span>', $body, 'its words');
});

// Found reviewing phase 1: with no character loaded, the picture's query resolved what the
// owner left to the character against the DEFAULT character, so a Soft site was previewed in
// Minimal's typeface and colour. It resolves against the site's own.
testBothDrivers('the preview is drawn with the site\'s own character when none is loaded', function (string $driver) {
    $db = adminSite($driver);
    App\Modules\Design\Composition::remember($db, 'soft');
    $body = dispatch('/admin/appearance')->body;
    preg_match('~<iframe name="design-preview" src="([^"]+)"~', $body, $frame);
    assertTrue(isset($frame[1]), 'the preview frame');
    $src = html_entity_decode($frame[1] ?? '');
    parse_str((string) parse_url($src, PHP_URL_QUERY), $query);
    assertEquals(App\Modules\Design\Characters::decisions('soft')['typography'], $query['typography'] ?? null, 'Soft\'s typeface in the picture');
    assertEquals(App\Modules\Design\Characters::decisions('soft')['seed'], $query['seed'] ?? null, 'and its colour');
});
