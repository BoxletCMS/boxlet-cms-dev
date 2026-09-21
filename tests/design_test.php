<?php

use App\Core\Response;
use App\Modules\Design\Color;
use App\Modules\Menus\Menu;
use App\Modules\Settings\SiteChrome;
use App\Modules\Design\Derived;
use App\Modules\Design\Design;
use App\Modules\Design\Palette;
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
        array_map(static fn (string $s): string => "text-{$s}", ['sm', 'base', 'lg', 'xl', '2xl', '3xl', '4xl']),
        array_map(static fn (string $s): string => "space-{$s}", ['xs', 's', 'm', 'l', 'xl', '2xl', '3xl']),
        ['radius-s', 'radius-m', 'radius-l', 'radius-button', 'shadow-s', 'shadow-m', 'shadow-l', 'border-width', 'border-card', 'container-width', 'container-narrow', 'container-wide'],
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

test('the five presets differ in structure, not only in colour', function () {
    $structural = ['typography', 'scale', 'spacing', 'radius', 'shadow', 'container', 'surface_contrast'];
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
    $result = Tokens::validate(['seed' => 'red', 'secondary' => '#12', 'typography' => 'comic-sans'] + Presets::get('minimal'));

    assertEquals(t('design.error.color'), $result['errors']['seed'] ?? null, 'seed');
    assertEquals(t('design.error.color'), $result['errors']['secondary'] ?? null, 'secondary');
    assertEquals(t('design.error.choice'), $result['errors']['typography'] ?? null, 'typography');
    assertEquals(Presets::get('minimal')['typography'], $result['decisions']['typography'], 'fallback typography');
});

test('compiled tokens carry a content hash and include only the pairing\'s fonts', function () {
    $dir = tmpPath('compile');
    removeTree($dir);
    $editorial = Presets::get('editorial');
    $file = (new TokenCompiler())->compile(Derived::from($editorial), $dir, Typography::fontFaces($editorial['typography'], '../assets/fonts'));
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
    assertEquals('editorial', Design::load($db)['typography'], 'saved typography');
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
    $colors = App\Modules\Design\Palette::colors('#ffe600', '', 'low');
    $pairs = App\Modules\Design\Palette::pairs($colors, false);
    $failures = App\Modules\Design\Palette::failures($colors, false);

    assertEquals(12, count($pairs), 'pairs measured');
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

    assertEquals(12, count($result['pairs'] ?? []), 'pairs in the response');
    assertEquals([], $result['errors'] ?? null, 'minimal passes, so no errors');
    foreach ($result['pairs'] as $pair) {
        assertTrue($pair['passes'] === true, $pair['pair'] . ' passes under minimal');
    }
});

test('the screen shows the gauge, and never folds away a pair that fails', function () {
    adminSite('sqlite');
    $body = dispatch('/admin/appearance')->body;

    // Six open, five folded, while everything passes.
    preg_match('~<ul class="gauge-list" data-gauge-open>(.*?)</ul>~s', $body, $open);
    assertEquals(6, substr_count($open[1] ?? '', 'class="gauge-row'), 'rows standing open');
    assertContains('data-pair="text_on_background"', $body, 'the first pair');
    assertContains('4.5', $body, 'what the rule asks for');

    // A grey seed fails three pairs, and one of them — text on the start of the gradient —
    // is the eleventh of twelve, which is inside the part that folds away. Measured, not
    // assumed: a failure the screen hides is the one thing this must never do.
    $failing = adminPost('/admin/appearance', designFields(['seed' => '#7f7f7f'] + Presets::get('minimal')) + ['action' => 'save']);
    preg_match('~<ul class="gauge-list" data-gauge-open>(.*?)</ul>~s', $failing->body, $openAgain);
    $folded = '';
    if (preg_match('~<ul class="gauge-list" data-gauge-folded>(.*?)</ul>~s', $failing->body, $hidden) === 1) {
        $folded = $hidden[1];
    }
    assertTrue(!str_contains($folded, 'gauge-fails'), 'a failing pair was folded out of sight');
    assertEquals(8, substr_count($openAgain[1] ?? '', 'class="gauge-row'), 'six, plus the two lifted out of the fold');
    assertEquals(3, substr_count($openAgain[1] ?? '', 'gauge-fails'), 'all three failures stand open');
    assertEquals(4, substr_count($folded, 'class="gauge-row'), 'the rest stay folded');
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
    // rem is allowed in exactly one place, because it is the unit that control is IN: the
    // width slider says "42rem · 672px". Nowhere else may leak the compiler's language.
    $remOnly = array_values(array_filter($readouts[1], static fn (string $r): bool => str_contains($r, 'rem')));
    assertEquals(1, count($remOnly), 'readouts mentioning rem: ' . implode(' | ', $remOnly));
    assertContains('·', $remOnly[0] ?? '', 'and it gives pixels beside it');
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

testBothDrivers('loading a character keeps the header and footer the owner has typed', function (string $driver) {
    $db = adminSite($driver);
    Menu::create($db, 'en', 'Main');

    // What the owner has on the screen: a menu, a footer line, and their own words.
    adminPost('/admin/appearance', appearanceFields([
        'header_menu' => 'Main',
        'footer_text_en' => 'Made in Zagreb',
        'header_button_label_en' => 'Write to us',
        'action' => 'save',
    ]));

    // Now they try a character. The whole screen is posted, because the card's button names
    // the one form — it used to be a form of its own carrying only the character's name,
    // which read back as "every chrome field is empty" and cleared them.
    $loaded = adminPost('/admin/appearance', appearanceFields([
        'header_menu' => 'Main',
        'footer_text_en' => 'Made in Zagreb',
        'header_button_label_en' => 'Write to us',
        'action' => 'preset:bold',
    ]));

    assertEquals(200, $loaded->status, 'the character loads');
    // In the textarea it was typed into, not merely somewhere on the page.
    assertContains('>Made in Zagreb</textarea>', $loaded->body, 'the footer line is still on the screen');
    assertContains('value="Write to us"', $loaded->body, 'the button label is still on the screen');
    assertContains('<option value="Main" selected>', $loaded->body, 'the menu is still chosen');
    assertEquals('Main', SiteChrome::menuName($db), 'and nothing was written');
    assertEquals('Made in Zagreb', SiteChrome::footer($db, 'en')['text'], 'the stored footer line');
});

test('the merged screen carries both halves, and the old addresses lead to it', function () {
    $db = adminSite('sqlite');
    $body = dispatch('/admin/appearance')->body;

    foreach (['colour', 'type', 'shape', 'page', 'chrome'] as $tab) {
        assertContains('data-panel="' . $tab . '"', $body, 'the ' . $tab . ' tab');
    }
    assertContains('name="seed"', $body, 'the design half');
    assertContains('name="header_menu"', $body, 'the chrome half');
    assertContains('name="footer_text_en"', $body, 'the words');

    foreach (['/admin/design', '/admin/chrome'] as $old) {
        assertEquals('/admin/appearance', dispatch($old)->headers['Location'] ?? null, $old . ' leads here');
    }
});

testBothDrivers('one publish writes the design and the header together', function (string $driver) {
    $db = adminSite($driver);
    Menu::create($db, 'en', 'Main');

    $response = adminPost('/admin/appearance', appearanceFields([
        'seed' => '#1f1fd1',
        'header_menu' => 'Main',
        'look_header_surface' => 'contrast',
        'footer_small_print_en' => '© Northwind',
        'action' => 'save',
    ]));

    assertRedirectedTo('/admin/appearance', $response);
    assertEquals('#1f1fd1', Design::load($db)['seed'], 'the design');
    assertEquals('Main', SiteChrome::menuName($db), 'the menu');
    assertEquals('contrast', App\Modules\Settings\ChromeLook::stored($db)['header_surface'], 'the look');
    assertEquals('© Northwind', SiteChrome::footer($db, 'en')['small_print'], 'the words');
});

test('the preview draws the words being typed, before anything is published', function () {
    $db = adminSite('sqlite');
    lookSite($db);

    $query = http_build_query(appearanceFields([
        'header_menu' => 'Main',
        'footer_text_en' => 'A line nobody has published',
        'header_button_label_en' => 'Press me',
        'header_button_url_en' => '/about',
    ]));
    $body = dispatch('/admin/appearance/preview?' . $query)->body;

    assertContains('A line nobody has published', $body, 'the footer line being typed');
    assertContains('Press me', $body, 'the button label being typed');
    assertEquals('', SiteChrome::footer($db, 'en')['text'], 'and nothing was written');
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

test('the content width is a number, and the four old names still mean what they meant', function () {
    foreach (['narrow' => '42', 'normal' => '56', 'wide' => '68', 'full' => '80'] as $name => $rem) {
        $decisions = Tokens::validate(['container' => $name] + Presets::get('minimal'))['decisions'];
        assertEquals($rem, $decisions['container'], "the old name {$name}");
    }

    // A number of its own, rounded to the step it is offered in.
    assertEquals('64', Tokens::validate(['container' => '64'] + Presets::get('minimal'))['decisions']['container'], 'a number');
    assertEquals('64', Tokens::validate(['container' => '63'] + Presets::get('minimal'))['decisions']['container'], 'rounded to the step');

    // Outside the bounds is refused and named, not quietly clamped: the owner asked for
    // something the design layer does not do, and saying so is the whole point of a refusal.
    $wide = Tokens::validate(['container' => '200'] + Presets::get('minimal'));
    assertContains('36', $wide['errors']['container'] ?? '', 'the message names the bounds');
    assertEquals('56', $wide['decisions']['container'], 'and falls back to the default');
    assertTrue(isset(Tokens::validate(['container' => 'enormous'] + Presets::get('minimal'))['errors']['container']), 'a word that is not one of the four');
});

test('the content width reaches the stylesheet as the number that was chosen', function () {
    $css = (new TokenCompiler())->css(Derived::from(['container' => '64'] + Presets::get('minimal')));

    assertContains('--container-width: 64rem;', $css, 'the width');
    // The narrow and wide containers follow it, so one decision still moves all three.
    assertContains('--container-narrow: 43.52rem;', $css, 'the narrow one');
    assertContains('--container-wide: 83.2rem;', $css, 'the wide one');
});

test('the text size moves the type and nothing else', function () {
    $normal = Derived::from(['text_size' => 'normal'] + Presets::get('minimal'));
    $larger = Derived::from(['text_size' => 'larger'] + Presets::get('minimal'));

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

    // A closed set is a row of radios now, not a dropdown (D-065).
    assertContains('name="text_size" value="large"', $body, 'the text size');
    assertContains('id="design-text_size-larger"', $body, 'each of its segments');
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
    $derived = Palette::colors($minimal['seed'], $minimal['secondary'], $minimal['surface_contrast']);

    // A near-black page with near-white text: every "ink on a colour" has to flip with it.
    $byHand = ['background' => '#0d0d10', 'text' => '#f4f4f6'];
    $mine = Palette::colors($minimal['seed'], $minimal['secondary'], $minimal['surface_contrast'], $byHand);

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
    $roles = array_keys(Palette::colors($minimal['seed'], $minimal['secondary'], $minimal['surface_contrast']));
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
    $css = (new TokenCompiler())->css(Derived::from(Design::load($db)));
    assertContains('--color-surface: #eceff4;', $css, 'the compiled token');
});

test('the screen offers the seven colours, folded away until one is the owner\'s', function () {
    $db = adminSite('sqlite');
    $shut = dispatch('/admin/appearance')->body;

    assertContains('<details class="by-hand">', $shut, 'the panel is folded while every colour is worked out');
    foreach (Palette::BY_HAND as $role) {
        assertContains('name="color_' . $role . '"', $shut, 'the ' . $role . ' colour');
        assertContains('name="color_' . $role . '_on"', $shut, 'and its switch');
    }

    Design::save($db, Tokens::validate(['color_text' => '#101010', 'color_text_on' => '1'] + Presets::get('minimal'))['decisions'], tmpPath('cache'));
    assertContains('<details class="by-hand" open>', dispatch('/admin/appearance')->body, 'and it is open once one is');
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

    // The same palette WITHOUT the lighter link is refused, and the refusal names the link.
    $unreadable = json_decode(dispatch('/admin/appearance/check?' . http_build_query(
        designFields(['color_background' => '#0d0d10', 'color_background_on' => '1'] + Presets::get('minimal'))
    ))->body, true);
    assertTrue(isset($unreadable['errors']['seed']), 'the seed is what is too dark now');
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
    assertEquals('1.2', $tooSteep['decisions']['scale'], 'and falls back to the default');
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
    $grotesk = ['typography' => 'grotesk'] + Presets::get('minimal');
    $pairing = App\Modules\Design\Typography::PAIRINGS['grotesk'];

    $following = Derived::from($grotesk);
    assertEquals($pairing['heading_weight'], $following['heading']['weight'], 'the pairing\'s weight');
    assertEquals($pairing['tracking'], $following['heading']['tracking'], 'the pairing\'s letter spacing');
    assertEquals($pairing['transform'], $following['heading']['transform'], 'the pairing\'s case');

    $mine = Derived::from(['heading_weight' => '400', 'tracking' => 'wide', 'caps' => 'yes'] + $grotesk);
    assertEquals('400', $mine['heading']['weight'], 'the weight that was chosen');
    assertEquals(Tokens::TRACKING['wide'], $mine['heading']['tracking'], 'the letter spacing that was chosen');
    assertEquals('uppercase', $mine['heading']['transform'], 'and the case');

    // A choice survives changing the typeface; what was never chosen follows the new one.
    $rounded = Derived::from(['typography' => 'rounded', 'heading_weight' => '400'] + $grotesk);
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
    $checked = json_decode(dispatch('/admin/appearance/check?' . http_build_query(designFields(['spacing' => 'generous'] + Presets::get('minimal'))))->body, true);
    assertEquals(Tokens::readable(['spacing' => 'generous'] + Presets::get('minimal'))['space'] . 'px', $checked['readouts']['spacing'] ?? '', 'the spacing it would come to');
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
        'boxed' => 'yes', 'frame' => 'wide', 'sheet_radius' => 'round', 'sheet_shadow' => 'shadow',
    ] + Presets::get('soft'))['decisions']);
    $flat = Derived::from(Tokens::validate([
        'boxed' => 'no', 'frame' => 'wide', 'sheet_radius' => 'round', 'sheet_shadow' => 'shadow',
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

test('how much room is around the sheet is a decision', function () {
    $decisions = static fn (string $frame): array => Tokens::validate(['boxed' => 'yes', 'frame' => $frame] + Presets::get('soft'))['decisions'];
    $thin = Derived::from($decisions('thin'))['page']['frame'];
    $wide = Derived::from($decisions('wide'))['page']['frame'];

    assertEquals('1.5rem', $thin, 'thin is one spacing unit');
    assertEquals('7.5rem', $wide, 'wide is five');
    assertTrue(isset(Tokens::validate(['frame' => 'enormous'] + Presets::get('soft'))['errors']['frame']), 'and nothing else is a frame');
});

testBothDrivers('cards have a colour of their own, between the page and a tinted section', function (string $driver) {
    $db = adminSite($driver);
    $minimal = Presets::get('minimal');
    $colors = Palette::colors($minimal['seed'], $minimal['secondary'], $minimal['surface_contrast']);

    assertTrue(isset($colors['card']), 'the palette has a card colour');
    assertTrue($colors['card'] !== $colors['background'] && $colors['card'] !== $colors['surface'],
        'and it is neither the page nor the tinted surface: ' . $colors['card']);

    // It is checked like every other surface text can land on.
    $pairs = array_column(Palette::pairs($colors, false), 'pair');
    assertTrue(in_array('text_on_card', $pairs, true), 'text on a card is measured');

    // And the owner may take it over, like the other six.
    adminPost('/admin/appearance', appearanceFields(['color_card' => '#eef1f4', 'color_card_on' => '1', 'action' => 'save']));
    assertEquals('#eef1f4', Design::load($db)['color_card'], 'the card colour that was published');
    assertContains('--color-card: #eef1f4;', (new TokenCompiler())->css(Derived::from(Design::load($db))), 'the compiled token');
});

testBothDrivers('the footer menu runs in as many columns as the chrome says', function (string $driver) {
    $db = adminSite($driver);
    lookSite($db);

    // The menu goes with it: one screen is one form, so a post that omits a field clears
    // it — the same rule that cost the site its header when a character card posted alone.
    adminPost('/admin/appearance', appearanceFields([
        'header_menu' => 'Main',
        'look_footer_layout' => 'columns',
        'look_footer_columns' => '4',
        'action' => 'save',
    ]));

    assertEquals('4', App\Modules\Settings\ChromeLook::stored($db)['footer_columns'], 'the choice');
    assertContains('footer-cols-4', dispatch('/')->body, 'and the class the footer draws with');
});
