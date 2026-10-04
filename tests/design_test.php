<?php

use App\Core\Response;
use App\Modules\Design\Color;
use App\Modules\Menus\Menu;
use App\Modules\Settings\SiteChrome;
use App\Modules\Design\Derived;
use App\Modules\Design\Design;
use App\Modules\Design\Palette;
use App\Modules\Design\PaletteInks;
use App\Modules\Design\PalettePairs;
use App\Modules\Design\Presets;
use App\Modules\Design\TokenCompiler;
use App\Modules\Design\Tokens;
use App\Modules\Design\Typography;

// Layers 0 and 1: colour maths, contrast checks, presets and token compilation.

/**
 * Every custom property the site's CSS relies on.
 *
 * @return list<string>
 */
function expectedProperties(): array
{
    $colors = ['background', 'surface', 'border', 'text', 'muted', 'accent', 'link', 'on-accent', 'contrast', 'on-contrast', 'muted-on-contrast', 'contrast-raised', 'gradient-start', 'gradient-end', 'on-gradient'];

    return array_merge(
        array_map(static fn (string $c): string => "color-{$c}", $colors),
        ['font-heading', 'font-body', 'heading-weight', 'heading-tracking', 'heading-transform', 'body-weight', 'leading-body', 'leading-heading'],
        array_map(static fn (string $s): string => "text-{$s}", ['sm', 'base', 'lg', 'xl', '2xl', '3xl', '4xl', 'small']),
        array_map(static fn (string $s): string => "space-{$s}", ['xs', 's', 'm', 'l', 'xl', '2xl', '3xl']),
        ['radius-s', 'radius-m', 'radius-l', 'radius-button', 'shadow-s', 'shadow-m', 'shadow-l', 'shadow-edge', 'border-width', 'border-card', 'container-width', 'container-narrow', 'container-wide', 'veil-least', 'link-line'],
    );
}

/**
 * @param array<string, string> $decisions
 * @return array<string, string> fields as the Design form submits them
 */
function designFields(array $decisions): array
{
    return ['use_secondary' => $decisions['secondary'] !== '' ? '1' : '0', 'secondary' => $decisions['secondary'] !== '' ? $decisions['secondary'] : '#000000'] + $decisions;
}

function linkedStylesheet(Response $response): string
{
    preg_match('~<link rel="stylesheet" href="(/cache/tokens\.[0-9a-f]{12}\.css)">~', $response->body, $match);

    return $match[1] ?? fail('the page links no compiled tokens stylesheet');
}

test('contrast ratios match WCAG reference values', function () {
    assertEquals('21.00', number_format(Color::contrast('#000000', '#ffffff'), 2), 'black on white');
    assertEquals('4.48', number_format(Color::contrast('#777777', '#ffffff'), 2), '#777 on white');
    assertEquals('8.59', number_format(Color::contrast('#0000ff', '#ffffff'), 2), 'blue on white');
});

test('OKLCH conversion round-trips sRGB colours', function () {
    foreach (['#8a1c2b', '#2f4f6f', '#ffe600', '#000000', '#ffffff', '#6d28d9'] as $hex) {
        [$lightness, $chroma, $hue] = Color::toOklch($hex);
        assertEquals($hex, Color::fromOklch($lightness, $chroma, $hue), $hex);
    }
});

foreach (Presets::names() as $name) {
    $preset = Presets::get($name);
    test("preset {$name} is a complete token set that passes every contrast check", function () use ($preset) {
        $result = Tokens::validate($preset);
        assertEquals([], $result['errors'], 'errors');
        assertEquals($preset, $result['decisions'], 'decisions survive validation unchanged');

        $css = (new TokenCompiler())->css(Derived::from($preset));
        foreach (expectedProperties() as $property) {
            assertContains("--{$property}: ", $css, 'compiled tokens');
        }
    });
}

// MUTED WORDS READ ON EVERY SURFACE THEY CAN STAND ON (PLAN.md D-183), under every character,
// light and dark: each surface's muted ink, as sections.css hands it out, on the surface and on
// a card raised from it; and on a header's or footer's own colour where a character sets one.
// A card in a contrast band put its muted lines at 3.8–4.5:1 under all five before.
test('every character\'s muted words read on every surface and on its cards, light and dark', function () {
    $surfaces = [
        'plain' => ['muted', ['background', 'card']],
        'tinted' => ['muted', ['surface', 'background']],
        'contrast' => ['muted-on-contrast', ['contrast', 'contrast-raised']],
        'image' => ['on-contrast', ['contrast', 'contrast-raised']],
        'gradient' => ['on-gradient', ['gradient-start', 'gradient-end']],
    ];
    $low = [];
    $measured = 0;
    foreach (App\Modules\Design\Characters::CORE as $name) {
        foreach (['light', 'dark'] as $mode) {
            $decisions = Presets::get($name);
            $decisions['mode'] = $mode;
            $colors = Palette::forDecisions($decisions);
            foreach ($surfaces as $surface => [$ink, $grounds]) {
                foreach ($grounds as $ground) {
                    $measured++;
                    $ratio = Color::contrast($colors[$ink], $colors[$ground]);
                    if ($ratio < Palette::AA_BODY) {
                        $low[] = sprintf('%s %s: %s on %s (%s) %.2f', $name, $mode, $ink, $ground, $surface, $ratio);
                    }
                }
            }
            foreach (Tokens::ownChrome($decisions) as $part => $own) {
                $inks = PaletteInks::inksOn($own, $colors);
                foreach ([$own, $inks['raised']] as $ground) {
                    $measured++;
                    $ratio = Color::contrast($inks['muted'], $ground);
                    if ($ratio < Palette::AA_BODY) {
                        $low[] = sprintf('%s %s: muted on the %s\'s own %s %.2f', $name, $mode, $part, $ground, $ratio);
                    }
                }
            }
        }
    }
    assertTrue($measured >= 5 * 2 * 10, "pairs measured: {$measured}");
    assertEquals([], $low, 'muted words under 4.5:1');
});

// DARK MODE LIFTS THE MAIN COLOUR (PLAN.md D-184, the owner): a seed chosen for a light page
// read at 1.9-3.1:1 as a link on a dark one, and every character was refused in dark mode. The
// links and the buttons take the same hue, lighter, until they read on the page, a card and the
// tinted surface; a link colour by hand the same; and a light page keeps the seed as it is.
test('in dark mode every character passes, its links and buttons a lighter variant of the main colour', function () {
    foreach (App\Modules\Design\Characters::CORE as $name) {
        $dark = Presets::get($name);
        $dark['mode'] = 'dark';
        assertEquals([], Tokens::validate($dark)['errors'], "{$name} in dark mode");
        $colors = Palette::forDecisions($dark);
        [$seedLightness, , $seedHue] = Color::toOklch($dark['seed']);
        [$lightness, , $hue] = Color::toOklch($colors['link']);
        assertTrue($lightness > $seedLightness, "{$name}: the link is lighter than the seed");
        assertTrue(abs($hue - $seedHue) < 3 || abs($hue - $seedHue) > 357 || Color::toOklch($colors['link'])[1] < 0.02, "{$name}: the link keeps the seed's hue");
        assertEquals($colors['link'], $colors['accent'], "{$name}: the buttons take the same variant");
        foreach (['background', 'card', 'surface'] as $ground) {
            assertTrue(Color::contrast($colors['link'], $colors[$ground]) >= 4.5, "{$name}: the link on the {$ground}");
        }
        assertTrue(Color::contrast($colors['on-accent'], $colors['accent']) >= 4.5, "{$name}: a button's words");

        $light = Palette::forDecisions(Presets::get($name));
        assertEquals(Color::normalizeHex($dark['seed']), $light['accent'], "{$name}: a light page keeps the seed");
    }

    // A seed with almost no hue goes to the text's ink, not to a middle grey (D-185, the
    // owner): Minimal's slate lifted was #778090, and a button of it looked disabled.
    $minimal = Presets::get('minimal');
    $minimal['mode'] = 'dark';
    $colors = Palette::forDecisions($minimal);
    assertEquals($colors['text'], $colors['accent'], 'Minimal in dark mode: the buttons take the text\'s ink');
    assertEquals($colors['text'], $colors['link'], 'and the links');
    assertTrue(Color::contrast($colors['on-accent'], $colors['accent']) >= 7.0, 'a light button with dark words');
    $css = (new TokenCompiler())->css(Derived::from(Tokens::resolve($minimal)));
    assertContains('--link-line: underline;', $css, 'a link in the text\'s colour is underlined');
    assertContains('--link-line: none;', (new TokenCompiler())->css(Derived::from(Tokens::resolve(Presets::get('minimal')))), 'and in the main colour it is not');

    // A hard shadow on a dark page is darker than the page, never the light text's ink
    // (D-185): it drew a light line under Brutalist's header and light edges to its cards.
    $brutalist = Presets::get('brutalist');
    $brutalist['mode'] = 'dark';
    $css = (new TokenCompiler())->css(Derived::from(Tokens::resolve($brutalist)));
    preg_match('~--color-background: (#[0-9a-f]{6});~', $css, $page);
    foreach (['shadow-s', 'shadow-m', 'shadow-l', 'shadow-edge'] as $size) {
        preg_match('~--' . $size . ': [^;]*(#[0-9a-f]{6});~', $css, $ink);
        assertTrue(isset($ink[1], $page[1]) && Color::toOklch($ink[1])[0] < Color::toOklch($page[1])[0], "Brutalist dark, {$size}: " . ($ink[1] ?? 'no colour') . ' on ' . ($page[1] ?? '?'));
    }

    $byHand = Presets::get('minimal');
    $byHand['mode'] = 'dark';
    $byHand['color_link'] = '#1d3557';
    $colors = Palette::forDecisions($byHand);
    assertTrue($colors['link'] !== '#1d3557' && Color::contrast($colors['link'], $colors['background']) >= 4.5, 'a link colour by hand is lifted too');
});

// A band's shadow falls straight down in every style: a hard one thrown to the right left a
// notch of the page at the left end of an edge-to-edge header (Brutalist, D-170).
test('a band\'s edge shadow has no sideways offset', function () {
    foreach (['soft', 'hard', 'layered', 'none'] as $style) {
        $edge = Derived::shadows($style, 40, '#111111')['edge'];
        assertTrue($edge === 'none' || str_starts_with($edge, '0 '), "{$style}: {$edge}");
    }
    assertEquals('0 4.8px 0 #111111', Derived::shadows('hard', 40, '#111111')['edge'], 'hard: its middle drop, straight down');
});

test('the five presets differ in structure, not only in colour', function () {
    $structural = ['heading_font', 'scale', 'spacing', 'radius', 'shadow', 'container', 'surface_contrast'];
    foreach (Presets::names() as $a) {
        $first = Presets::get($a);
        foreach (Presets::names() as $b) {
            $second = Presets::get($b);
            if ($a < $b) {
                $different = count(array_filter($structural, static fn (string $key): bool => $first[$key] !== $second[$key]));
                assertTrue($different >= 4, "{$a} and {$b} differ in only {$different} structural decisions");
            }
        }
    }
    $editorial = Presets::get('editorial');
    $brutalist = Presets::get('brutalist');
    foreach ($structural as $key) {
        assertTrue($editorial[$key] !== $brutalist[$key], "editorial and brutalist share {$key}");
    }
});

test('a main colour too light to read fails, naming the pair and the ratio', function () {
    $result = Tokens::validate(['seed' => '#ffe600'] + Presets::get('minimal'));
    $message = $result['errors']['seed'] ?? fail('no error on the seed');

    assertContains(t('design.pair.links_on_background'), $message, 'seed error');
    assertTrue((bool) preg_match('~is 1\.\d\d:1; WCAG AA needs at least 4\.5:1~', $message), "no ratio in: {$message}");
});

test('invalid colours and choices are refused and named', function () {
    $result = Tokens::validate(['seed' => 'red', 'secondary' => '#12', 'heading_font' => 'comic-sans'] + Presets::get('minimal'));

    assertEquals(t('design.error.color'), $result['errors']['seed'] ?? null, 'seed');
    assertEquals(t('design.error.color'), $result['errors']['secondary'] ?? null, 'secondary');
    assertEquals(t('design.error.choice'), $result['errors']['heading_font'] ?? null, 'a family the library does not have');
    // Refused values follow the character (D-164): '' is a value every key can hold.
    assertEquals('', $result['decisions']['heading_font'], 'and it follows the character');
});

test('compiled tokens carry a content hash and include only the pairing\'s fonts', function () {
    $dir = tmpPath('compile');
    removeTree($dir);
    $editorial = Presets::get('editorial');
    $file = (new TokenCompiler())->compile(Derived::from($editorial), $dir, Typography::fontFaces([$editorial['heading_font'], $editorial['body_font']], '../assets/fonts'));
    $css = (string) file_get_contents($dir . '/' . $file);

    assertTrue((bool) preg_match('~^tokens\.[0-9a-f]{12}\.css$~', $file), "file name {$file}");
    assertContains('--color-accent: #8a1c2b;', $css, 'tokens');
    assertContains('url("../assets/fonts/playfair-display/playfair-display-latin-wght.woff2")', $css, 'heading font');
    assertContains('source-serif-4-latin-ext-wght.woff2', $css, 'body font with Croatian characters');
    assertTrue(!str_contains($css, 'ibm-plex-mono'), 'a font the pairing does not use');
    foreach (glob(dirname(__DIR__) . '/public/assets/fonts/*/*.woff2') ?: [] as $font) {
        assertTrue(filesize($font) > 1000, basename($font) . ' is not a font file');
    }
});

test('changing a token changes the stylesheet name and removes the old file', function () {
    $dir = tmpPath('compile');
    removeTree($dir);
    $compiler = new TokenCompiler();
    $minimal = Derived::from(Presets::get('minimal'));
    $first = $compiler->compile($minimal, $dir);
    $second = $compiler->compile(Derived::from(['seed' => '#1f6f3f'] + Presets::get('minimal')), $dir);

    assertTrue($first !== $second, 'the name did not change with the tokens');
    assertTrue(!is_file($dir . '/' . $first), 'the old stylesheet was kept');
    assertEquals($first, $compiler->compile($minimal, $dir), 'the same tokens give the same name');
});

testBothDrivers('saving the design recompiles, and pages link the new stylesheet without a hard refresh', function (string $driver) {
    $db = adminSite($driver);
    createPage($db, 'en', 'about', 'About');
    $before = linkedStylesheet(dispatch('/about'));

    assertRedirectedTo('/admin/appearance', adminPost('/admin/appearance', designFields(Presets::get('editorial')) + ['action' => 'save']));
    $after = linkedStylesheet(dispatch('/about'));
    assertTrue($after !== $before, 'the page still links the old stylesheet');
    assertTrue(is_file(tmpPath('cache') . '/' . basename($after)), 'the linked stylesheet does not exist');
    assertEquals('playfair-display', Design::load($db)['heading_font'], 'the heading family saved');
});

testBothDrivers('a design that fails contrast is refused and changes nothing', function (string $driver) {
    $db = adminSite($driver);
    createPage($db, 'en', 'about', 'About');
    $before = linkedStylesheet(dispatch('/about'));

    $response = adminPost('/admin/appearance', designFields(['seed' => '#ffe600'] + Presets::get('minimal')) + ['action' => 'save']);
    assertEquals(422, $response->status, 'status');
    assertContains('data-error-for="seed" role="alert">' . e(t('design.pair.links_on_background')), $response->body, 'error at the seed field');
    assertEquals($before, linkedStylesheet(dispatch('/about')), 'stylesheet');
    assertEquals(0, (int) ($db->one('SELECT COUNT(*) AS n FROM design_tokens')['n'] ?? -1), 'stored decisions');
});

test('using a preset fills the form and saves nothing', function () {
    $db = adminSite('sqlite');
    $response = adminPost('/admin/appearance', ['action' => 'preset:brutalist']);

    assertEquals(200, $response->status, 'status');
    assertContains('name="seed" value="#1f1fd1"', $response->body, 'brutalist seed in the form');
    assertEquals(0, (int) ($db->one('SELECT COUNT(*) AS n FROM design_tokens')['n'] ?? -1), 'stored decisions');
});

test('the preview reflects submitted values and may only be framed by the site', function () {
    adminSite('sqlite');
    $preview = dispatch('/admin/appearance/preview?preset=bold&specimen=1');

    assertEquals(200, $preview->status, 'status');
    assertContains("frame-ancestors 'self'", $preview->headers['Content-Security-Policy'] ?? '', 'CSP');
    // The stylesheet is asked the SAME QUESTION the preview was asked, whatever form it
    // came in: a preset by name stays a preset by name, rather than being expanded here and
    // expanded again there.
    assertContains('/admin/appearance/stylesheet?preset=bold', $preview->body, 'preview stylesheet link');
    assertContains('surface-gradient', $preview->body, 'specimen sections');
    assertContains('--color-accent: #6d28d9;', dispatch('/admin/appearance/stylesheet?preset=bold')->body, 'that stylesheet is Bold\'s');
    $css = dispatch('/admin/appearance/stylesheet?seed=%236d28d9&secondary=%231e1045&use_secondary=1&typography=grotesk&scale=1.5&spacing=normal&radius=round&shadow=layered&container=wide&surface_contrast=high');
    assertContains('--color-accent: #6d28d9;', $css->body, 'preview tokens');
    assertEquals('text/css; charset=utf-8', $css->headers['Content-Type'] ?? null, 'content type');
});

test('the check endpoint returns contrast errors keyed by decision', function () {
    adminSite('sqlite');
    $query = http_build_query(designFields(['seed' => '#ffe600'] + Presets::get('minimal')));
    $result = json_decode(dispatch('/admin/appearance/check?' . $query)->body, true);

    assertContains(t('design.pair.links_on_background'), (string) ($result['errors']['seed'] ?? ''), 'seed error');
    assertEquals('#ffe600', $result['colors']['accent'] ?? null, 'derived accent');
});

test('the design screen and its endpoints require an admin session', function () {
    installedSite(['en' => 'English']);

    foreach (['/admin/appearance', '/admin/appearance/preview', '/admin/appearance/stylesheet', '/admin/appearance/check'] as $path) {
        assertEquals('/admin/login', dispatch($path)->headers['Location'] ?? null, $path);
    }
});

test('a missing stylesheet is recompiled on the next request', function () {
    $db = installedSite(['en' => 'English']);
    createPage($db, 'en', 'about', 'About');
    $file = tmpPath('cache') . '/' . basename(linkedStylesheet(dispatch('/about')));
    unlink($file);

    assertEquals(basename($file), basename(linkedStylesheet(dispatch('/about'))), 'same design, same name');
    assertTrue(is_file($file), 'the stylesheet was not recompiled');
});

// ---- Round 2: the loop closes (PLAN.md D-058) -----------------------------------------

test('every pair is measured, and the failures are exactly the ones that do not pass', function () {
    $colors = App\Modules\Design\Palette::colors('#ffe600', '', 20.0);
    $pairs = App\Modules\Design\PalettePairs::pairs($colors, false);
    $failures = App\Modules\Design\PalettePairs::failures($colors, false);

    // Fourteen since D-183, which added the muted words on a card, on the page and in a
    // contrast band: the rule widened on purpose, not a count matched to new output.
    assertEquals(14, count($pairs), 'pairs measured');
    foreach ($pairs as $pair) {
        assertTrue($pair['ratio'] > 0, 'a ratio for ' . $pair['pair']);
        assertEquals($pair['ratio'] >= 4.5, $pair['passes'], 'the verdict for ' . $pair['pair']);
        assertTrue($pair['foreground'] !== $pair['background'], 'two colours for ' . $pair['pair']);
    }

    $failed = array_values(array_map(
        static fn (array $pair): string => $pair['pair'],
        array_filter($pairs, static fn (array $pair): bool => !$pair['passes']),
    ));
    assertEquals($failed, array_map(static fn (array $f): string => $f['pair'], $failures), 'failures against the same list');
    assertTrue($failed !== [], 'a yellow seed fails something');
});

test('the check endpoint carries every pair, not only the failures', function () {
    adminSite('sqlite');
    $query = http_build_query(designFields(Presets::get('minimal')));
    $result = json_decode(dispatch('/admin/appearance/check?' . $query)->body, true);

    // 14 since D-183's two muted-on-a-card pairs, on purpose.
    assertEquals(14, count($result['pairs'] ?? []), 'pairs in the response');
    assertEquals([], $result['errors'] ?? null, 'minimal passes, so no errors');
    foreach ($result['pairs'] as $pair) {
        assertTrue($pair['passes'] === true, $pair['pair'] . ' passes under minimal');
    }
});

/*
 * THE CHECK IN ONE LINE, WITH EVERY PAIR BEHIND IT (D-160). This was "six open, the rest
 * folded, and a failing pair never folded"; the rule changed deliberately when the gauge
 * became a verdict: all twelve rows are in one list behind a <details>, closed while
 * everything passes and OPEN when anything fails — so a failure is still never out of sight,
 * which is the half of the old rule that mattered.
 */
test('the screen shows the contrast check, and never folds away a pair that fails', function () {
    adminSite('sqlite');
    $body = dispatch('/admin/appearance')->body;

    assertEquals(14, substr_count($body, 'class="gauge-row'), 'every pair is listed (14 since D-183)');
    assertContains('data-pair="text_on_background"', $body, 'the first pair');
    assertContains('4.5', $body, 'what the rule asks for');
    assertContains('<details class="gauge-more">', $body, 'the list, closed while all pass');
    assertContains('<span class="contrast-tally" data-contrast-tally>14/14</span>', $body, 'the verdict');
    assertContains('data-contrast-fails hidden', $body, 'no failure said');

    // A grey seed fails three pairs. The list opens.
    $failing = adminPost('/admin/appearance', designFields(['seed' => '#7f7f7f'] + Presets::get('minimal')) + ['action' => 'save']);
    assertContains('<details class="gauge-more" open>', $failing->body, 'the list, open on a failure');
    assertEquals(3, substr_count($failing->body, 'gauge-row gauge-fails'), 'all three failures listed');
    assertContains(e(t('inspector.contrast.fail_many', ['count' => 3])), $failing->body, 'how many, in a line');
    // The seed fails them, and no colour by hand: nothing for "Fix automatically" to do.
    assertContains('data-contrast-fix hidden', $failing->body, 'a button that could fix nothing');
});

test('the decisions are shown as numbers a person reads, never as CSS', function () {
    adminSite('sqlite');
    $body = dispatch('/admin/appearance')->body;
    // The numbers live beside the controls they belong to now (D-065): a readout on the
    // label's line, and the specimen for the type.
    preg_match_all('~<(?:span|output) class="readout[^"]*"[^>]*>(.*?)</(?:span|output)>~s', $body, $readouts);
    preg_match_all('~<em data-specimen-size="[^"]*">(.*?)</em>~s', $body, $specimen);
    $numbers = implode(' ', array_merge($readouts[1], $specimen[1]));

    assertTrue(!str_contains($numbers, 'clamp('), 'the screen printed a clamp()');
    $readable = Tokens::readable(Presets::get(Presets::DEFAULT));
    assertContains($readable['text']['base'] . 'px', $numbers, 'the body size');
    assertContains($readable['radius'] . 'px', $numbers, 'the corner radius');
    assertContains($readable['container'] . 'px', $numbers, 'the content width');
    // rem is allowed only where it is the unit the control is IN (D-164: a decision that is
    // a number of rem — the spacing, the content's width, the sheet's, the side margin).
    // Each gives pixels beside it; nowhere else may leak the compiler's language.
    $remOnly = array_values(array_filter($readouts[1], static fn (string $r): bool => str_contains($r, 'rem')));
    $inRem = count(array_filter(App\Modules\Design\Vocabulary\Decisions::ALL, static fn (array $d): bool => ($d['unit'] ?? '') === 'rem'));
    // The spacing's readout appears twice: in its section and in Quick start's mirror.
    assertEquals($inRem + 1, count($remOnly), 'readouts mentioning rem: ' . implode(' | ', $remOnly));
    foreach ($remOnly as $readout) {
        assertContains('·', $readout, 'and it gives pixels beside it');
    }
});

test('Publish stands in the screen\'s own bar and still submits the form', function () {
    adminSite('sqlite');
    $body = dispatch('/admin/appearance')->body;

    // In the bar over the whole screen, which never scrolls at all: the columns scroll
    // under it, so no amount of reading moves the one button that acts (D-064).
    preg_match('~<div class="appearance-bar">(.*?)<form~s', $body, $bar);
    assertContains('value="save"', $bar[1] ?? '', 'Publish stands in the bar');
    assertContains('data-state', $bar[1] ?? '', 'and so does what state the screen is in');
    assertContains('<button type="submit" form="design-form" name="action" value="save"', $body, 'the button names its form');
    assertContains('id="design-form"', $body, 'the form it names');

    // And outside the form element, which is the whole point: it must not be a child of it.
    preg_match('~<form id="design-form".*?</form>~s', $body, $form);
    assertTrue(!str_contains($form[0] ?? '', 'name="action" value="save"'), 'the button is still inside the form');

    // And it still saves: the button is outside the form element, so this is not a detail
    // the markup alone can settle.
    $saved = adminPost('/admin/appearance', designFields(Presets::get('bold')) + ['action' => 'save']);
    assertRedirectedTo('/admin/appearance', $saved);
});

// ---- Round 3: one screen (PLAN.md D-059) ----------------------------------------------

test('the merged screen carries both halves, and the old addresses lead to it', function () {
    $db = adminSite('sqlite');
    $body = dispatch('/admin/appearance')->body;

    // Sections since D-157, which replaced the six tabs of D-111.
    foreach (array_keys(App\Modules\Appearance\Overrides::SECTIONS) as $section) {
        assertContains('data-view="' . $section . '"', $body, 'the ' . $section . ' section');
    }
    assertTrue(!str_contains($body, 'data-panel='), 'a tab of the old screen');
    assertContains('name="seed"', $body, 'the design half');
    assertContains('name="look_header_arrangement"', $body, 'the chrome\'s look');
    // The rule changed deliberately (D-180): the header's and footer's words and menus are
    // Navigation's, and this screen only links there (appearance_navigation_test).
    assertTrue(!str_contains($body, 'name="header_menu"'), 'the header\'s menu is Navigation\'s');
    assertTrue(!str_contains($body, 'name="footer_text_en"'), 'and the words');

    /*
     * AND THE TWO OLD ADDRESSES ARE GONE (PLAN.md O-22).
     *
     * They were redirects for one release, which is the grace a bookmark gets. The rule
     * changed deliberately: this asserted that each one LED here and now asserts that
     * neither exists, because a redirect kept for ever is a second address for one screen —
     * the arrangement the merge was for. The ⌘K entry never named them; it names Appearance
     * and carries "design" and "chrome" among its words, so searching still finds it.
     */
    foreach (['/admin/design', '/admin/chrome'] as $old) {
        assertEquals(404, dispatch($old)->status, $old . ' is not an address any more');
    }
});

test('the picture has a toolbar, and it is not there for anyone without a script', function () {
    adminSite('sqlite');
    $body = dispatch('/admin/appearance')->body;

    foreach (['1280', '834', '390'] as $width) {
        assertContains('data-viewport="' . $width . '"', $body, 'the ' . $width . ' width');
    }
    assertContains('data-zoom', $body, 'the zoom');
    assertContains('data-compare', $body, 'Compare');
    assertContains('data-stage', $body, 'the stage the frame is scaled inside');

    // Hidden in the markup, shown by the script that makes it work: a row of controls that
    // did nothing would be worse than none (D-060).
    assertContains('data-preview-tools hidden', $body, 'the tools start hidden');
    assertContains('data-revert hidden', $body, 'so does Discard changes');

    // The three words the state can say, carried on the element rather than in the script,
    // because they are translated and it is not.
    foreach (['published', 'unpublished', 'problem'] as $state) {
        assertContains('data-' . $state . '="', $body, 'the wording for ' . $state);
    }
});

// ---- Round 5: the two decisions that were coarser than the question (D-062) ------------

// The four names it had are not read any more (D-162): a name is a word that is not a
// number, refused like any other.
test('the content width is a number, and a name is not one', function () {
    assertTrue(isset(Tokens::validate(['container' => 'normal'] + Presets::get('minimal'))['errors']['container']), 'an old name');

    // A number of its own, rounded to the step it is offered in.
    assertEquals('64', Tokens::validate(['container' => '64'] + Presets::get('minimal'))['decisions']['container'], 'a number');
    assertEquals('64', Tokens::validate(['container' => '63'] + Presets::get('minimal'))['decisions']['container'], 'rounded to the step');

    // Outside the bounds is refused and named, not quietly clamped: the owner asked for
    // something the design layer does not do, and saying so is the whole point of a refusal.
    $wide = Tokens::validate(['container' => '200'] + Presets::get('minimal'));
    assertContains('36', $wide['errors']['container'] ?? '', 'the message names the bounds');
    assertEquals('', $wide['decisions']['container'], 'and follows the character (D-164)');
    assertTrue(isset(Tokens::validate(['container' => 'enormous'] + Presets::get('minimal'))['errors']['container']), 'a word');
});

test('the content width reaches the stylesheet as the number that was chosen', function () {
    $css = (new TokenCompiler())->css(Derived::from(['container' => '64'] + Presets::get('minimal')));

    assertContains('--container-width: 64rem;', $css, 'the width');
    // The narrow and wide containers follow it, so one decision still moves all three: two
    // thirds and seven sixths since D-164 (README 1.6: 640 / 960 / 1120px at 60rem).
    assertContains('--container-narrow: 42.667rem;', $css, 'the narrow one');
    assertContains('--container-wide: 74.667rem;', $css, 'the wide one');
});

test('the text size moves the type and nothing else', function () {
    $normal = Derived::from(['text_size' => '16'] + Presets::get('minimal'));
    $larger = Derived::from(['text_size' => '18'] + Presets::get('minimal'));

    assertEquals('1rem', $normal['text']['base'], 'the base at normal');
    assertEquals('1.125rem', $larger['text']['base'], 'the base at larger');
    assertTrue($normal['text']['4xl'] !== $larger['text']['4xl'], 'the largest heading moves too');

    // Everything that is not type stays exactly where it was.
    foreach (['space', 'radius', 'container', 'page'] as $group) {
        assertEquals($normal[$group], $larger[$group], $group . ' did not move');
    }
});

test('the screen offers a text size and a real slider for the width', function () {
    adminSite('sqlite');
    $body = dispatch('/admin/appearance')->body;

    // A number of pixels since D-164, a slider with its old names as marks.
    assertContains('<input type="range" id="design-text_size" name="text_size" min="14" max="20" step="0.5"', $body, 'the text size');
    assertContains('>Larger</text>', $body, 'a mark under it');
    assertContains('<input type="range" id="design-container" name="container"', $body, 'the width is a slider');
    assertContains('min="36"', $body, 'its smallest');
    assertContains('max="88"', $body, 'its largest');
    assertContains('step="2"', $body, 'and the step it moves in');
});

testBothDrivers('a width outside the bounds is refused wherever it arrives, and a good one publishes', function (string $driver) {
    $db = adminSite($driver);

    $checked = json_decode(dispatch('/admin/appearance/check?' . http_build_query(designFields(['container' => '900'] + Presets::get('minimal'))))->body, true);
    assertTrue(isset($checked['errors']['container']), 'the check endpoint refuses it');

    assertRedirectedTo('/admin/appearance', adminPost('/admin/appearance', appearanceFields(['container' => '48', 'action' => 'save'])));
    assertEquals('48', Design::load($db)['container'], 'the width that was published');
});

// ---- Round 6: colours by hand, with the check as the guarantee (D-063) ------------------

test('a colour set by hand is used exactly, and the ones that depend on it are worked out again', function () {
    $minimal = Presets::get('minimal');
    $derived = Palette::colors($minimal['seed'], $minimal['secondary'], (float) $minimal['surface_contrast']);

    // A near-black page with near-white text: every "ink on a colour" has to flip with it.
    $byHand = ['background' => '#0d0d10', 'text' => '#f4f4f6'];
    $mine = Palette::colors($minimal['seed'], $minimal['secondary'], (float) $minimal['surface_contrast'], $byHand);

    assertEquals('#0d0d10', $mine['background'], 'the background is exactly what was set');
    assertEquals('#f4f4f6', $mine['text'], 'and so is the text');

    // THE BUG THIS ROUND EXISTS TO PREVENT: on-accent, on-contrast and on-gradient are
    // chosen from the background and the text. Applied after a hand-set colour they would
    // still be answers about the colour that has gone.
    foreach (['on-accent', 'on-contrast', 'on-gradient'] as $dependent) {
        assertTrue(
            in_array($mine[$dependent], [$mine['background'], $mine['text']], true),
            $dependent . ' is one of the palette\'s own inks, and it is ' . $mine[$dependent],
        );
        assertTrue($mine[$dependent] !== $derived[$dependent], $dependent . ' did not move with the colours it is made from');
    }
});

test('only the seven independent roles can be set by hand', function () {
    $minimal = Presets::get('minimal');
    $roles = array_keys(Palette::colors($minimal['seed'], $minimal['secondary'], (float) $minimal['surface_contrast']));
    $onOffer = Palette::BY_HAND;
    sort($onOffer);
    $left = array_values(array_diff($roles, Palette::BY_HAND));
    sort($left);

    assertEquals(['background', 'border', 'card', 'link', 'muted', 'surface', 'text'], $onOffer, 'the roles on offer');
    // Every other role the palette holds, named: the two seeds under their own names, and
    // the five inks that go ON a colour. Choosing one of those is choosing whether text can
    // be read, which is the palette's job and the reason the check can be trusted.
    assertEquals(
        ['accent', 'contrast', 'contrast-raised', 'gradient-end', 'gradient-start', 'muted-on-contrast', 'on-accent', 'on-contrast', 'on-gradient'],
        $left,
        'the roles that are not on offer',
    );
});

test('a hand-set colour that cannot be read is refused, and the message names its own control', function () {
    $minimal = Presets::get('minimal');

    // Pale grey text on the default near-white page: legible to nobody.
    $result = Tokens::validate(['color_text' => '#cccccc'] + $minimal);

    assertTrue(isset($result['errors']['color_text']), 'the refusal lands on the colour that caused it');
    assertContains('4.5', $result['errors']['color_text'], 'and says what was needed');
    assertTrue(!isset($result['errors']['surface_contrast']), 'and not on a control that cannot fix it');

    // The same palette with nothing set by hand passes, so the refusal is the colour's own.
    assertEquals([], Tokens::validate($minimal)['errors'], 'the character itself is fine');
});

testBothDrivers('a colour is the owner\'s only while its switch is on', function (string $driver) {
    $db = adminSite($driver);

    // Sent without the switch: the field still carries a colour, and it is not a choice.
    adminPost('/admin/appearance', appearanceFields(['color_surface' => '#eceff4', 'action' => 'save']));
    assertEquals('', Design::load($db)['color_surface'], 'a colour without its switch is not the owner\'s');

    adminPost('/admin/appearance', appearanceFields(['color_surface' => '#eceff4', 'color_surface_on' => '1', 'action' => 'save']));
    assertEquals('#eceff4', Design::load($db)['color_surface'], 'with the switch, it is');

    // And it reaches the site's stylesheet as itself.
    $css = (new TokenCompiler())->css(Derived::from(Design::resolved($db)));
    assertContains('--color-surface: #eceff4;', $css, 'the compiled token');
});

/*
 * THE SHAPE CHANGED ON PURPOSE (D-074), so this case was split rather than adjusted.
 *
 * It used to assert two things at once: that the seven colours the owner may take over are
 * offered with their switches, and that they sit in a panel folded away until one of them is
 * theirs. The first is untouched and is asserted here. The second is the rule that was
 * deliberately dropped — the same palette was in two places, and the half that could be
 * CHANGED was the half that was closed — so what replaces it is asserted as its own case
 * below: one list, every role in it, and a way back for a role that has been taken.
 */
test('the screen offers the seven colours the owner may take over', function () {
    adminSite('sqlite');
    $shut = dispatch('/admin/appearance')->body;

    foreach (Palette::BY_HAND as $role) {
        assertContains('name="color_' . $role . '"', $shut, 'the ' . $role . ' colour');
        assertContains('name="color_' . $role . '_on"', $shut, 'and its switch');
    }
});

test('the palette is one list, and a colour the owner has taken can go back to it', function () {
    $db = adminSite('sqlite');
    $shut = dispatch('/admin/appearance')->body;

    // The old shape: a read-only list of the derived colours, and a folded panel beside it.
    assertTrue(!str_contains($shut, 'class="by-hand"'), 'the folded panel is gone');
    assertTrue(!str_contains($shut, 'class="swatches"'), 'and so is the list that repeated it');
    // Counted inside the palette's own group: since D-111 the three colours of one's own
    // are drawn as rows of the same shape, under their own groups, and they are not roles
    // the palette works out. Since D-157 the roles nobody sets by hand stand behind "show
    // the other roles" in the same group — still a row each.
    $paletteList = (string) substr($shut, (int) strpos($shut, 'id="group-colours-palette"'));
    $paletteList = (string) substr($paletteList, 0, (int) strpos($paletteList, 'id="group-colours-contrast"'));
    // Since D-187 each colour by hand has a second row, its dark one, shown while Mode is Dark:
    // counted apart, so the light rows are still one per role.
    $rows = static fn (string $html): int => substr_count($html, '<li class="role') - substr_count($html, '<li class="role role-dark');
    $darkRows = static fn (string $html): int => substr_count($html, '<li class="role role-dark');
    assertEquals(
        count(Palette::colors(Presets::get(Presets::DEFAULT)['seed'], '', 20.0)),
        $rows($paletteList),
        'every role the palette works out is a row',
    );
    assertEquals(count(Palette::BY_HAND), $darkRows($paletteList), 'and each one by hand its dark row');
    assertEquals(3, $rows($shut) - $rows($paletteList), 'and the three colours of one\'s own are rows of the same shape');
    assertEquals(2, $darkRows($shut) - $darkRows($paletteList), 'the header\'s and footer\'s with their dark rows; the page\'s has none');
    /*
     * EVERY WAY BACK IS ALWAYS DRAWN, and whether one SHOWS is a CSS question: the switches
     * flip under the owner's hand as colours are picked (D-065), so a button the server
     * decided about would always be a round trip behind. What the HTML can be asked is
     * whether the actions exist, and — separately — which roles are the owner's.
     */
    assertContains('value="colour:free"', $shut, 'the whole palette can be taken back');
    foreach (Palette::BY_HAND as $role) {
        assertContains('value="colour:free:' . $role . '"', $shut, 'and so can ' . $role . ' on its own');
    }

    /*
     * WHICH ROLES ARE THE OWNER'S is carried by the switch, not by the colour input: an
     * input always has SOME colour in it, which is the whole reason the switch exists.
     */
    $mine = static fn (string $body): bool => str_contains($body, 'name="color_text_on" value="1" checked');
    assertTrue(!$mine($shut), 'nothing is the owner\'s to begin with');

    Design::save($db, Tokens::validate(['color_text' => '#101010', 'color_text_on' => '1'] + Presets::get('minimal'))['decisions'], tmpPath('cache'));
    assertTrue($mine(dispatch('/admin/appearance')->body), 'a saved colour comes back as the owner\'s');

    // And the action gives it back ON THE SCREEN, without publishing anything: the site
    // still has what was last published until Publish is pressed (D-059).
    $freed = adminPost('/admin/appearance', appearanceFields([
        'color_text' => '#101010',
        'color_text_on' => '1',
        'action' => 'colour:free:text',
    ]))->body;
    assertTrue(!$mine($freed), 'and pressing the way back makes it the palette\'s again');
    assertEquals('#101010', Design::load($db)['color_text'], 'while the site is untouched until Publish');
});

test('a dark page set by hand works out its own palette, and the preview draws it', function () {
    adminSite('sqlite');
    // ONE COLOUR AND A LINK. Everything else — the tinted surface, the border, the text, the
    // muted text — follows the page it is on, which is what makes a hand-set background a
    // design rather than a list of refusals (D-063). The link is the seed, and the seed is
    // the owner's: a dark page needs a lighter one, and the check says so by name.
    $dark = ['color_background' => '#0d0d10', 'color_background_on' => '1', 'color_link' => '#8ab4f8', 'color_link_on' => '1'];
    $query = http_build_query(designFields($dark + Presets::get('minimal')));

    $css = dispatch('/admin/appearance/stylesheet?' . $query)->body;
    assertContains('--color-background: #0d0d10;', $css, 'the background being tried');
    assertContains('--color-link: #8ab4f8;', $css, 'and the link');

    $checked = json_decode(dispatch('/admin/appearance/check?' . $query)->body, true);
    assertEquals('#0d0d10', $checked['colors']['background'] ?? '', 'the gauge measures the same palette');
    assertEquals([], $checked['errors'], 'nothing in it is unreadable');
    foreach ($checked['pairs'] as $pair) {
        assertTrue($pair['passes'], $pair['pair'] . ' is ' . $pair['ratio']);
    }

    // The same palette WITHOUT the lighter link: it was refused, naming the seed. THE RULE
    // CHANGED DELIBERATELY with D-184: on a dark page the main colour is lifted until it reads,
    // so nothing is refused and the link is the seed's hue, lighter.
    $lifted = json_decode(dispatch('/admin/appearance/check?' . http_build_query(
        designFields(['color_background' => '#0d0d10', 'color_background_on' => '1'] + Presets::get('minimal'))
    ))->body, true);
    assertEquals([], $lifted['errors'], 'the seed is lifted, not refused');
    assertTrue(Color::contrast($lifted['colors']['link'] ?? '#000000', '#0d0d10') >= 4.5, 'the link reads on the dark page');
});

// ---- Round 9: type in detail (D-066) ---------------------------------------------------

test('the step between sizes is a number, and the characters keep the ratios they had', function () {
    foreach (Presets::names() as $name) {
        $preset = Presets::get($name);
        assertEquals($preset['scale'], Tokens::validate($preset)['decisions']['scale'], $name . ' keeps its ratio exactly');
    }

    assertEquals('1.42', Tokens::validate(['scale' => '1.42'] + Presets::get('minimal'))['decisions']['scale'], 'a number of its own');
    $tooSteep = Tokens::validate(['scale' => '2.4'] + Presets::get('minimal'));
    assertTrue(isset($tooSteep['errors']['scale']), 'outside the bounds is refused');
    assertEquals('', $tooSteep['decisions']['scale'], 'and follows the character (D-164)');
});

test('a nudge moves one step and leaves the scale alone', function () {
    $minimal = Presets::get('minimal');
    $plain = Derived::from($minimal);
    $nudged = Derived::from(['nudge_h1' => '16'] + $minimal);

    // 16px is 1rem, added after the ratio has run. The largest heading is a clamp(), so the
    // size itself is asked for rather than parsed back out of the CSS.
    assertEquals(
        round(Derived::sizeOf($minimal, '4xl') + 1, 4),
        round(Derived::sizeOf(['nudge_h1' => '16'] + $minimal, '4xl'), 4),
        'one rem larger, exactly',
    );
    assertContains('clamp(', $nudged['text']['4xl'], 'the largest heading still shrinks on a phone');
    assertEquals($plain['text']['2xl'], $nudged['text']['2xl'], 'the step below it did not move');
    assertEquals($plain['text']['base'], $nudged['text']['base'], 'nor did the body');

    $readable = Tokens::readable(['nudge_h1' => '16'] + $minimal);
    assertEquals(Tokens::readable($minimal)['text']['4xl'] + 16, $readable['text']['4xl'], 'the nudge is those pixels, exactly');

    // Both directions, and bounded: a heading can take more than it can lose.
    assertEquals('-30', Tokens::validate(['nudge_h1' => '-30'] + $minimal)['decisions']['nudge_h1'], 'the smallest it goes');
    assertTrue(isset(Tokens::validate(['nudge_h1' => '-31'] + $minimal)['errors']['nudge_h1']), 'and no further');
    assertTrue(isset(Tokens::validate(['nudge_sm' => '9'] + $minimal)['errors']['nudge_sm']), 'small print has its own bounds');
});

test('the heading treatment follows the typeface until it is taken over', function () {
    // The family's own since D-185, where it was the pairing's: Space Grotesk's are Grotesk's.
    $grotesk = ['heading_font' => 'space-grotesk', 'body_font' => 'inter'] + Presets::get('minimal');
    $pairing = App\Modules\Design\Typography::PAIRINGS['grotesk'];

    $following = Derived::from($grotesk);
    assertEquals($pairing['heading_weight'], $following['heading']['weight'], 'the family\'s weight');
    assertEquals($pairing['tracking'], $following['heading']['tracking'], 'the family\'s letter spacing');
    assertEquals($pairing['transform'], $following['heading']['transform'], 'the family\'s case');

    $mine = Derived::from(['heading_weight' => '400', 'tracking' => '0.06', 'caps' => 'yes'] + $grotesk);
    assertEquals('400', $mine['heading']['weight'], 'the weight that was chosen');
    assertEquals('0.06em', $mine['heading']['tracking'], 'the letter spacing that was chosen');
    assertEquals('uppercase', $mine['heading']['transform'], 'and the case');

    // A choice survives changing the typeface; what was never chosen follows the new one.
    $rounded = Derived::from(['heading_font' => 'nunito', 'heading_weight' => '400'] + $grotesk);
    assertEquals('400', $rounded['heading']['weight'], 'the weight stayed the owner\'s');
    assertEquals(App\Modules\Design\Typography::PAIRINGS['rounded']['tracking'], $rounded['heading']['tracking'], 'the spacing followed');
});

test('every readout the screen shows comes from one place', function () {
    adminSite('sqlite');
    $readouts = App\Modules\Appearance\AppearanceForm::readouts(Presets::get('minimal'));
    $body = dispatch('/admin/appearance')->body;

    foreach (['text_size', 'scale', 'spacing', 'radius', 'container', 'nudge_h1', 'specimen.4xl'] as $name) {
        assertTrue(isset($readouts[$name]), 'the server works out ' . $name);
        assertContains('data-readout="' . $name . '"', $body, 'the screen names ' . $name);
    }

    // And the check endpoint returns them, so a readout follows the control being dragged
    // instead of holding the number the page was rendered with.
    $checked = json_decode(dispatch('/admin/appearance/check?' . http_build_query(designFields(['spacing' => '1.5'] + Presets::get('minimal'))))->body, true);
    assertEquals('1.5rem · ' . Tokens::readable(['spacing' => '1.5'] + Presets::get('minimal'))['space'] . 'px', $checked['readouts']['spacing'] ?? '', 'the spacing it would come to');

    // A button's corners at the top of their range are a pill, and say so (D-171).
    assertEquals(t('design.button_radius.pill'), App\Modules\Appearance\AppearanceForm::readouts(['button_radius' => '28'] + Presets::get('minimal'))['button_radius'] ?? '', 'the pill');
    assertEquals('12px', App\Modules\Appearance\AppearanceForm::readouts(['button_radius' => '12'] + Presets::get('minimal'))['button_radius'] ?? '', 'below it, the pixels');
});

// ---- Round 9: the sheet, and what breaks out of it (D-067) -----------------------------

test('the frame wraps the sheet, and the chrome chooses which side of it to be on', function () {
    $db = adminSite('sqlite');
    lookSite($db);

    // Boxed, with the header breaking out: the header is a child of the page, the sections
    // are inside the sheet, and the frame is between them.
    Design::save($db, Tokens::validate([
        'boxed' => 'yes', 'header_bleed' => 'full', 'footer_bleed' => 'sheet',
    ] + Presets::get('soft'))['decisions'], tmpPath('cache'));
    $body = dispatch('/')->body;

    assertContains('<div class="page">', $body, 'the page');
    assertContains('<div class="page-frame">', $body, 'the frame');
    assertContains('<div class="page-sheet">', $body, 'the sheet');
    $frame = strpos($body, '<div class="page-frame">');
    assertTrue(strpos($body, '<header') < $frame, 'the header is outside the frame');
    assertTrue(strpos($body, '<footer') > $frame, 'the footer is inside it');

    // And the other way round.
    Design::save($db, Tokens::validate([
        'boxed' => 'yes', 'header_bleed' => 'sheet', 'footer_bleed' => 'full',
    ] + Presets::get('soft'))['decisions'], tmpPath('cache'));
    $swapped = dispatch('/')->body;
    $frame = strpos($swapped, '<div class="page-frame">');
    assertTrue(strpos($swapped, '<header') > $frame, 'the header is inside the frame now');
    assertTrue(strrpos($swapped, '<footer') > strpos($swapped, '</div>'), 'and the footer is out of it');
});

test('the sheet\'s corners and lift are zero unless the page is boxed', function () {
    $boxed = Derived::from(Tokens::validate([
        'boxed' => 'yes', 'frame' => '5', 'sheet_radius' => '20', 'sheet_shadow' => 'shadow',
    ] + Presets::get('soft'))['decisions']);
    $flat = Derived::from(Tokens::validate([
        'boxed' => 'no', 'frame' => '5', 'sheet_radius' => '20', 'sheet_shadow' => 'shadow',
    ] + Presets::get('soft'))['decisions']);

    assertTrue($boxed['page']['frame'] !== '0', 'a boxed page has a frame: ' . $boxed['page']['frame']);
    assertTrue($boxed['page']['sheet-radius'] !== '0', 'and corners');
    assertTrue($boxed['page']['sheet-shadow'] !== 'none', 'and a lift');

    // Not conditionals in the stylesheet: the tokens themselves are zero, which is what
    // keeps every rule free of "is this boxed" (D-031, D-067).
    assertEquals('0', $flat['page']['frame'], 'an unboxed page has no frame');
    assertEquals('0', $flat['page']['sheet-radius'], 'no corners');
    assertEquals('none', $flat['page']['sheet-shadow'], 'and no lift');
});

// The side margin is rem of its own since D-164; it was spacing units by name.
test('how much room is around the sheet is a decision', function () {
    $decisions = static fn (string $frame): array => Tokens::validate(['boxed' => 'yes', 'frame' => $frame] + Presets::get('soft'))['decisions'];
    $thin = Derived::from($decisions('1'))['page']['frame'];
    $wide = Derived::from($decisions('6'))['page']['frame'];

    assertEquals('1rem', $thin, 'one rem');
    assertEquals('6rem', $wide, 'six, the most');
    assertTrue(isset(Tokens::validate(['frame' => '7'] + Presets::get('soft'))['errors']['frame']), 'and no further');
    assertTrue(isset(Tokens::validate(['frame' => 'wide'] + Presets::get('soft'))['errors']['frame']), 'nor a name');
});

testBothDrivers('cards have a colour of their own, between the page and a tinted section', function (string $driver) {
    $db = adminSite($driver);
    $minimal = Presets::get('minimal');
    $colors = Palette::colors($minimal['seed'], $minimal['secondary'], (float) $minimal['surface_contrast']);

    assertTrue(isset($colors['card']), 'the palette has a card colour');
    assertTrue($colors['card'] !== $colors['background'] && $colors['card'] !== $colors['surface'],
        'and it is neither the page nor the tinted surface: ' . $colors['card']);

    // It is checked like every other surface text can land on.
    $pairs = array_column(PalettePairs::pairs($colors, false), 'pair');
    assertTrue(in_array('text_on_card', $pairs, true), 'text on a card is measured');

    // And the owner may take it over, like the other six.
    adminPost('/admin/appearance', appearanceFields(['color_card' => '#eef1f4', 'color_card_on' => '1', 'action' => 'save']));
    assertEquals('#eef1f4', Design::load($db)['color_card'], 'the card colour that was published');
    assertContains('--color-card: #eef1f4;', (new TokenCompiler())->css(Derived::from(Design::resolved($db))), 'the compiled token');
});

testBothDrivers('the footer menu runs in as many columns as the chrome says', function (string $driver) {
    $db = adminSite($driver);
    lookSite($db);

    // The menu goes with it: one screen is one form, so a post that omits a field clears
    // it — the same rule that cost the site its header when a character card posted alone.
    saveChrome([
        'header_menu' => 'Main',
        'look_footer_layout' => 'columns',
        'look_footer_columns' => '4',
        'action' => 'save',
    ]);

    assertEquals('4', App\Modules\Settings\ChromeLook::stored($db)['footer_columns'], 'the choice');
    assertContains('footer-cols-4', dispatch('/')->body, 'and the class the footer draws with');
});

/*
 * OR A COLOUR OF YOUR OWN (PLAN.md D-076, docs/ispravci.md §C3).
 *
 * Three places take a shade of the palette or a colour the owner picked. The frame around a
 * boxed page carries no text, so it needs nothing but the colour; the header and the footer
 * carry text, so the ink on them is DERIVED from the colour and then measured.
 */
testBothDrivers('the header and the footer may take a colour of their own, with the ink worked out from it', function (string $driver) {
    $db = adminSite($driver);

    adminPost('/admin/appearance', appearanceFields([
        'header_colour' => '#1b3a2f', 'header_colour_on' => '1',
        'footer_colour' => '#f3e9d2', 'footer_colour_on' => '1',
        'action' => 'save',
    ]));
    $stored = Design::load($db);
    assertEquals('#1b3a2f', $stored['header_colour'], 'the header colour that was published');
    assertEquals('#f3e9d2', $stored['footer_colour'], 'the footer colour that was published');

    $css = (new TokenCompiler())->css(Derived::from(App\Modules\Design\Tokens::resolve($stored)));
    assertContains('--chrome-header-bg: #1b3a2f;', $css, 'the header carries its colour as a token');
    assertContains('--chrome-footer-bg: #f3e9d2;', $css, 'and so does the footer');

    // The ink is not a colour from the palette that happened to be there: it is chosen
    // against THIS surface, and it reads on it.
    $colors = Palette::forDecisions(Tokens::resolve($stored));
    foreach (['#1b3a2f', '#f3e9d2'] as $surface) {
        $inks = PaletteInks::inksOn($surface, $colors);
        assertTrue(Color::contrast($inks['text'], $surface) >= Palette::AA_BODY,
            'the text reads on ' . $surface . ': ' . number_format(Color::contrast($inks['text'], $surface), 2));
        assertTrue(Color::contrast($inks['muted'], $surface) >= Palette::AA_BODY,
            'and so does the muted text: ' . number_format(Color::contrast($inks['muted'], $surface), 2));
    }

    // And the gauge says so, by name, so a person can see the number rather than trust it.
    $pairs = array_column(PalettePairs::pairs($colors, false, [], Tokens::ownChrome($stored)), 'pair');
    foreach (['text_on_header', 'muted_on_header', 'text_on_footer', 'muted_on_footer'] as $pair) {
        assertTrue(in_array($pair, $pairs, true), $pair . ' is measured');
    }
});

/*
 * THE TWO COLOURS ANYBODY PICKS FIRST.
 *
 * A fixed step of 0.42 in lightness away from the surface is a comfortable muted tone in the
 * middle of the range and breaks at both ends: black put the muted text at 2.48:1 and white
 * at 4.29:1, so Boxlet refused both. The derivation walks until the pair reads,
 * which is why this is a test of the RESULT and not of the step.
 */
testBothDrivers('black and white are colours the header may take', function (string $driver) {
    $db = adminSite($driver);
    $decisions = Presets::get('minimal');
    $colors = Palette::colors($decisions['seed'], $decisions['secondary'], (float) $decisions['surface_contrast']);

    foreach (['#000000', '#ffffff'] as $surface) {
        $inks = PaletteInks::inksOn($surface, $colors);
        assertTrue(Color::contrast($inks['muted'], $surface) >= Palette::AA_BODY,
            'muted text reads on ' . $surface . ': ' . number_format(Color::contrast($inks['muted'], $surface), 2));
        // And it is MUTED, not the text colour over again: a second ink identical to the
        // first says the design has no quiet tone at all.
        assertTrue($inks['muted'] !== $inks['text'], 'and it is a quieter tone than the text on ' . $surface);

        $result = Tokens::validate(['header_colour' => $surface] + $decisions);
        assertEquals('', $result['errors']['header_colour'] ?? '', 'nothing is refused for ' . $surface);
    }
});

testBothDrivers('a colour neither ink can be read on is refused, naming the control', function (string $driver) {
    $db = adminSite($driver);
    // A mid grey: the light ink and the dark ink both land just under AA on it.
    $result = Tokens::validate(['header_colour' => '#7a7a7a'] + Presets::get('minimal'));

    assertTrue(isset($result['errors']['header_colour']), 'the refusal is the header colour\'s own');
    assertContains('4.5', $result['errors']['header_colour'], 'and it says what was needed');
    // The colour is KEPT rather than replaced, so the screen can show what was refused.
    assertEquals('#7a7a7a', $result['decisions']['header_colour'], 'the colour that was refused');
});

// A place's own colour is the owner's, and a character leaves it to the palette — except
// where the owner decided otherwise: Brutalist's footer is near-black (D-172), because its
// contrast surface is the yellow of its call to action and a gradient is not brutalist.
// Whatever a character sets passes the same contrast check as the owner's would.
testBothDrivers('a character gives a place a colour of its own only where the owner decided it', function (string $driver) {
    $db = adminSite($driver);
    $decided = ['brutalist' => ['footer_colour' => '#111318']];
    foreach (['editorial', 'minimal', 'bold', 'soft', 'brutalist'] as $name) {
        $decisions = Presets::get($name);
        foreach (App\Modules\Design\Vocabulary\Decisions::OWN_COLOURS as $field) {
            assertTrue(array_key_exists($field, $decisions), $name . ' carries ' . $field);
            assertEquals($decided[$name][$field] ?? '', $decisions[$field], $name . '\'s ' . $field);
        }
        assertEquals([], Tokens::validate($decisions)['errors'], $name . ' passes its own contrast check');
    }
});

testBothDrivers('the page\'s own colour paints what surrounds a boxed page, and gains no pair', function (string $driver) {
    $db = adminSite($driver);

    adminPost('/admin/appearance', appearanceFields([
        'boxed' => 'yes',
        'page_background_colour' => '#101010', 'page_background_colour_on' => '1',
        'action' => 'save',
    ]));
    $stored = Design::load($db);
    assertEquals('#101010', $stored['page_background_colour'], 'the colour that was published');
    assertContains('--page-bg: #101010;', (new TokenCompiler())->css(Derived::from(App\Modules\Design\Tokens::resolve($stored))), 'and what the frame is painted with');

    // No text sits on it, so it adds nothing to the gauge — the reasoning that has always
    // made this decision harmless.
    $colors = Palette::forDecisions(Tokens::resolve($stored));
    $pairs = PalettePairs::pairs($colors, false, [], Tokens::ownChrome($stored));
    foreach ($pairs as $pair) {
        assertTrue(!str_contains($pair['decision'], 'page_background_colour'), 'no pair belongs to the page background');
    }
});

testBothDrivers('a place gives its colour back to the palette in one press', function (string $driver) {
    $db = adminSite($driver);
    adminPost('/admin/appearance', appearanceFields([
        'header_colour' => '#1b3a2f', 'header_colour_on' => '1',
        'action' => 'save',
    ]));
    assertEquals('#1b3a2f', Design::load($db)['header_colour'], 'the colour is the owner\'s');

    // The button beside it, which re-renders rather than publishing: the screen shows the
    // palette's colour again and Publish is what makes it so.
    $body = adminPost('/admin/appearance', appearanceFields([
        'header_colour' => '#1b3a2f', 'header_colour_on' => '1',
        'action' => 'colour:free:header_colour',
    ]))->body;
    assertTrue(!str_contains($body, 'name="header_colour_on" value="1" checked'),
        'the switch beside the header colour is off again');
    assertEquals('#1b3a2f', Design::load($db)['header_colour'], 'and nothing was published by pressing it');

    // "Reset all" frees these three along with the seven palette roles: the button says one
    // thing, so it does one thing.
    $body = adminPost('/admin/appearance', appearanceFields([
        'header_colour' => '#1b3a2f', 'header_colour_on' => '1',
        'color_text' => '#222222', 'color_text_on' => '1',
        'action' => 'colour:free',
    ]))->body;
    assertTrue(!str_contains($body, 'name="header_colour_on" value="1" checked'), 'the header colour is the palette\'s again');
    assertTrue(!str_contains($body, 'name="color_text_on" value="1" checked'), 'and so is the text colour');
});

/*
 * NO BARE KEY ON THE SCREEN (D-110). `design.space_ramp` stood on the Shape tab as itself,
 * in capitals, from D-065 until this test existed: t() returns a missing key as the key,
 * which keeps a page from breaking and also keeps anyone from noticing. The prefixes are
 * read off the language files rather than listed, so a new file's keys are covered.
 */
test('the Appearance screen shows no translation key as itself', function () {
    $db = adminSite('sqlite');
    $body = dispatch('/admin/appearance')->body;

    $prefixes = [];
    foreach (glob(dirname(__DIR__) . '/lang/en/*.php') ?: [] as $file) {
        foreach (array_keys(require $file) as $key) {
            $prefixes[explode('.', (string) $key)[0]] = true;
        }
    }
    $text = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    preg_match_all('~(?<![\w./-])(' . implode('|', array_map('preg_quote', array_keys($prefixes))) . ')\.[a-z0-9_]+(?:\.[a-z0-9_]+)*(?![\w.-])~i', $text, $found);
    assertEquals([], array_values(array_unique($found[0])), 'keys shown as themselves');
});

/*
 * EVERY FIELD THE FORM READS IS ON THE SCREEN ONCE, AND ONLY ONCE (D-111).
 *
 * The screen is one form, and a field it does not send is one the owner cleared (D-059) —
 * which is how loading a character once wiped the header. Splitting the chrome tab in two
 * and moving three design decisions between tabs is exactly the kind of edit that drops a
 * field on the floor or draws it twice, so this reads every panel and counts.
 */
test('every decision and chrome choice is in exactly one section', function () {
    $db = adminSite('sqlite');
    $body = dispatch('/admin/appearance')->body;

    // Sections since D-157. Quick start's mirrors are left out: they belong to a form that
    // is never sent (#appearance-quick), and are a script's copy of a field, not a field.
    $onTab = [];
    foreach (array_keys(App\Modules\Appearance\Overrides::SECTIONS) as $tab) {
        $start = strpos($body, 'data-view="' . $tab . '"');
        assertTrue($start !== false, 'the ' . $tab . ' section');
        $end = strpos($body, 'data-view="', $start + 1);
        $panel = substr($body, (int) $start, $end === false ? null : $end - (int) $start);
        preg_match_all('~<(?:input|select|textarea)\b[^>]*>~', $panel, $tags);
        $names = [];
        foreach ($tags[0] as $tag) {
            if (!str_contains($tag, 'form="appearance-quick"') && preg_match('~ name="([a-z_0-9]+)"~', $tag, $named) === 1) {
                $names[] = $named[1];
            }
        }
        foreach (array_unique($names) as $name) {
            $onTab[$name][] = $tab;
        }
    }

    $expected = App\Modules\Design\Vocabulary\Decisions::keys('decisions');
    foreach (App\Modules\Settings\ChromeLook::keys() as $choice) {
        $expected[] = App\Modules\Settings\ChromeLook::field($choice);
    }
    // The menus and the words are Navigation's since D-180, and no section carries them.
    foreach (['header_menu', 'footer_menu_1', App\Modules\Settings\ChromeWords::field('small_print', 'en')] as $gone) {
        assertTrue(!isset($onTab[$gone]), $gone . ' is Navigation\'s, and drawn here');
    }
    $missing = [];
    $twice = [];
    foreach ($expected as $name) {
        if (!isset($onTab[$name])) {
            $missing[] = $name;
        } elseif (count($onTab[$name]) > 1) {
            $twice[] = $name . ' on ' . implode(' and ', $onTab[$name]);
        }
    }
    assertEquals([], $missing, 'fields the form reads and no section carries');
    assertEquals([], $twice, 'fields drawn in two sections');
    // And every width is in Layout & widths (D-157), which is where they moved from the
    // Header and Footer tabs of D-111.
    foreach (['container', 'boxed', 'sheet_width', 'header_width', 'header_bleed', 'footer_width', 'footer_bleed'] as $width) {
        assertEquals(['layout'], $onTab[$width] ?? [], $width);
    }
});

/*
 * THE PICTURE CAN BE OF ANY PUBLISHED PAGE (D-111), not only the home page: a header laid
 * over the first section looks different over a page without a hero. A draft, or a page of
 * another language, is not on the site and so is not offered and not drawn.
 */
testBothDrivers('the preview draws the page that is asked for, and only a published one', function (string $driver) {
    $db = adminSite($driver);
    createPage($db, 'en', '', 'Home', true, [['type' => 'text', 'content' => ['heading' => 'Front door', 'body' => '<p>Home.</p>']]]);
    $about = createPage($db, 'en', 'about', 'About', true, [['type' => 'text', 'content' => ['heading' => 'Zebra crossing', 'body' => '<p>About.</p>']]]);
    $draft = createPage($db, 'en', 'draft', 'Draft', false, [['type' => 'text', 'content' => ['heading' => 'Unfinished', 'body' => '<p>Draft.</p>']]]);

    $screen = dispatch('/admin/appearance')->body;
    assertContains('<option value="' . $about . '">About</option>', $screen, 'the published page is offered');
    assertTrue(!str_contains($screen, '<option value="' . $draft . '">'), 'the draft is not');

    assertContains('Front door', dispatch('/admin/appearance/preview')->body, 'the home page by default');
    $asked = dispatch('/admin/appearance/preview?page=' . $about)->body;
    assertContains('Zebra crossing', $asked, 'the page that was asked for');
    assertTrue(!str_contains($asked, 'Front door'), 'and not the home page');
    assertContains('Front door', dispatch('/admin/appearance/preview?page=' . $draft)->body, 'a draft falls back to the home page');
});

// The owner's model, D-123: Header content is Text, Boxed or Full, and Full is the bar's
// own width — the window when the bar runs across it, even on a boxed page.
test('header content can line up with the window on a boxed page, as well as with the box and the text', function () {
    $window = Derived::from(Tokens::validate(['boxed' => 'yes', 'header_bleed' => 'full', 'header_width' => 'window', 'footer_width' => 'window', 'sheet_width' => '72'] + Presets::get('soft'))['decisions']);
    assertEquals('100%', $window['page']['header-width'], 'the header\'s contents across the bar');
    assertEquals('100%', $window['page']['footer-width'], 'and the footer\'s');
    assertEquals('window', Tokens::validate(['header_width' => 'window'] + Presets::get('soft'))['decisions']['header_width'], 'the third answer is kept');

    // Characters whose page is not boxed ask for the window by name now: `full` on such a
    // page meant the window already, and says "Boxed" on the screen since D-123.
    foreach (Presets::names() as $name) {
        $character = Presets::get($name);
        if ($character['boxed'] === 'no') {
            assertTrue($character['header_width'] !== 'sheet', "{$name} asks for the box on a page that has none");
        }
    }
});

// The owner's detail on D-116: "full width" for the header follows the sheet on a boxed page.
test('a full-width header on a boxed page runs to the sheet\'s width, and to the window\'s otherwise', function () {
    // `sheet` since D-164: the stored name says what it lines up with.
    $boxed = Derived::from(Tokens::validate(['boxed' => 'yes', 'header_width' => 'sheet', 'sheet_width' => '72'] + Presets::get('soft'))['decisions']);
    $flat = Derived::from(Tokens::validate(['boxed' => 'no', 'header_width' => 'sheet', 'sheet_width' => '72'] + Presets::get('soft'))['decisions']);
    $content = Derived::from(Tokens::validate(['boxed' => 'yes', 'header_width' => 'content', 'sheet_width' => '72'] + Presets::get('soft'))['decisions']);

    assertEquals('calc(72rem - 2 * var(--space-l))', $boxed['page']['header-width'], 'as wide as the sheet, less the container\'s own padding');
    assertEquals('72rem', $boxed['page']['sheet-width'], 'which is the sheet');
    assertEquals('100%', $flat['page']['header-width'], 'the window, when the sheet is the window');
    assertEquals('none', $flat['page']['sheet-width'], 'and no sheet width then');
    assertEquals('60rem', $content['page']['header-width'], 'the content, when that is the choice');

    // The footer's contents answer the same question with their own decision.
    $footer = Derived::from(Tokens::validate(['boxed' => 'yes', 'footer_width' => 'sheet', 'header_width' => 'content', 'sheet_width' => '72'] + Presets::get('soft'))['decisions']);
    assertEquals('calc(72rem - 2 * var(--space-l))', $footer['page']['footer-width'], 'the footer to the sheet');
    assertEquals('60rem', $footer['page']['header-width'], 'while the header keeps to the content');
    assertEquals('60rem', $content['page']['footer-width'], 'and content is every character\'s default for the footer');
});
