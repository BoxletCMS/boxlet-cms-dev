<?php

use App\Core\Response;
use App\Modules\Design\Color;
use App\Modules\Design\Design;
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

foreach (Presets::ALL as $name => $preset) {
    test("preset {$name} is a complete token set that passes every contrast check", function () use ($preset) {
        $result = Tokens::validate($preset);
        assertEquals([], $result['errors'], 'errors');
        assertEquals($preset, $result['decisions'], 'decisions survive validation unchanged');

        $css = (new TokenCompiler())->css(Tokens::derive($preset));
        foreach (expectedProperties() as $property) {
            assertContains("--{$property}: ", $css, 'compiled tokens');
        }
    });
}

test('the five presets differ in structure, not only in colour', function () {
    $structural = ['typography', 'scale', 'spacing', 'radius', 'shadow', 'container', 'surface_contrast'];
    foreach (Presets::ALL as $a => $first) {
        foreach (Presets::ALL as $b => $second) {
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
    $file = (new TokenCompiler())->compile(Tokens::derive($editorial), $dir, Typography::fontFaces($editorial['typography'], '../assets/fonts'));
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
    $minimal = Tokens::derive(Presets::get('minimal'));
    $first = $compiler->compile($minimal, $dir);
    $second = $compiler->compile(Tokens::derive(['seed' => '#1f6f3f'] + Presets::get('minimal')), $dir);

    assertTrue($first !== $second, 'the name did not change with the tokens');
    assertTrue(!is_file($dir . '/' . $first), 'the old stylesheet was kept');
    assertEquals($first, $compiler->compile($minimal, $dir), 'the same tokens give the same name');
});

testBothDrivers('saving the design recompiles, and pages link the new stylesheet without a hard refresh', function (string $driver) {
    $db = adminSite($driver);
    createPage($db, 'en', 'about', 'About');
    $before = linkedStylesheet(dispatch('/about'));

    assertRedirectedTo('/admin/design', adminPost('/admin/design', designFields(Presets::get('editorial')) + ['action' => 'save']));
    $after = linkedStylesheet(dispatch('/about'));
    assertTrue($after !== $before, 'the page still links the old stylesheet');
    assertTrue(is_file(tmpPath('cache') . '/' . basename($after)), 'the linked stylesheet does not exist');
    assertEquals('editorial', Design::load($db)['typography'], 'saved typography');
});

testBothDrivers('a design that fails contrast is refused and changes nothing', function (string $driver) {
    $db = adminSite($driver);
    createPage($db, 'en', 'about', 'About');
    $before = linkedStylesheet(dispatch('/about'));

    $response = adminPost('/admin/design', designFields(['seed' => '#ffe600'] + Presets::get('minimal')) + ['action' => 'save']);
    assertEquals(422, $response->status, 'status');
    assertContains('data-error-for="seed" role="alert">' . e(t('design.pair.links_on_background')), $response->body, 'error at the seed field');
    assertEquals($before, linkedStylesheet(dispatch('/about')), 'stylesheet');
    assertEquals(0, (int) ($db->one('SELECT COUNT(*) AS n FROM design_tokens')['n'] ?? -1), 'stored decisions');
});

test('using a preset fills the form and saves nothing', function () {
    $db = adminSite('sqlite');
    $response = adminPost('/admin/design', ['action' => 'preset:brutalist']);

    assertEquals(200, $response->status, 'status');
    assertContains('name="seed" value="#1f1fd1"', $response->body, 'brutalist seed in the form');
    assertEquals(0, (int) ($db->one('SELECT COUNT(*) AS n FROM design_tokens')['n'] ?? -1), 'stored decisions');
});

test('the preview reflects submitted values and may only be framed by the site', function () {
    adminSite('sqlite');
    $preview = dispatch('/admin/design/preview?preset=bold&specimen=1');

    assertEquals(200, $preview->status, 'status');
    assertContains("frame-ancestors 'self'", $preview->headers['Content-Security-Policy'] ?? '', 'CSP');
    assertContains('/admin/design/stylesheet?seed=%236d28d9', $preview->body, 'preview stylesheet link');
    assertContains('surface-gradient', $preview->body, 'specimen sections');
    $css = dispatch('/admin/design/stylesheet?seed=%236d28d9&secondary=%231e1045&use_secondary=1&typography=grotesk&scale=1.5&spacing=normal&radius=round&shadow=layered&container=wide&surface_contrast=high');
    assertContains('--color-accent: #6d28d9;', $css->body, 'preview tokens');
    assertEquals('text/css; charset=utf-8', $css->headers['Content-Type'] ?? null, 'content type');
});

test('the check endpoint returns contrast errors keyed by decision', function () {
    adminSite('sqlite');
    $query = http_build_query(designFields(['seed' => '#ffe600'] + Presets::get('minimal')));
    $result = json_decode(dispatch('/admin/design/check?' . $query)->body, true);

    assertContains(t('design.pair.links_on_background'), (string) ($result['errors']['seed'] ?? ''), 'seed error');
    assertEquals('#ffe600', $result['colors']['accent'] ?? null, 'derived accent');
});

test('the design screen and its endpoints require an admin session', function () {
    installedSite(['en' => 'English']);

    foreach (['/admin/design', '/admin/design/preview', '/admin/design/stylesheet', '/admin/design/check'] as $path) {
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

    assertEquals(11, count($pairs), 'pairs measured');
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
    $result = json_decode(dispatch('/admin/design/check?' . $query)->body, true);

    assertEquals(11, count($result['pairs'] ?? []), 'pairs in the response');
    assertEquals([], $result['errors'] ?? null, 'minimal passes, so no errors');
    foreach ($result['pairs'] as $pair) {
        assertTrue($pair['passes'] === true, $pair['pair'] . ' passes under minimal');
    }
});

test('the screen shows the gauge, and never folds away a pair that fails', function () {
    adminSite('sqlite');
    $body = dispatch('/admin/design')->body;

    // Six open, five folded, while everything passes.
    preg_match('~<ul class="gauge-list" data-gauge-open>(.*?)</ul>~s', $body, $open);
    assertEquals(6, substr_count($open[1] ?? '', 'class="gauge-row'), 'rows standing open');
    assertContains('data-pair="text_on_background"', $body, 'the first pair');
    assertContains('4.5', $body, 'what the rule asks for');

    // A grey seed fails three pairs, and one of them — text on the start of the gradient —
    // is the tenth of eleven, which is inside the part that folds away. Measured, not
    // assumed: a failure the screen hides is the one thing this must never do.
    $failing = adminPost('/admin/design', designFields(['seed' => '#7f7f7f'] + Presets::get('minimal')) + ['action' => 'save']);
    preg_match('~<ul class="gauge-list" data-gauge-open>(.*?)</ul>~s', $failing->body, $openAgain);
    $folded = '';
    if (preg_match('~<ul class="gauge-list" data-gauge-folded>(.*?)</ul>~s', $failing->body, $hidden) === 1) {
        $folded = $hidden[1];
    }
    assertTrue(!str_contains($folded, 'gauge-fails'), 'a failing pair was folded out of sight');
    assertEquals(7, substr_count($openAgain[1] ?? '', 'class="gauge-row'), 'six, plus the one lifted out of the fold');
    assertEquals(3, substr_count($openAgain[1] ?? '', 'gauge-fails'), 'all three failures stand open');
    assertEquals(4, substr_count($folded, 'class="gauge-row'), 'the rest stay folded');
});

test('the decisions are shown as numbers a person reads, never as CSS', function () {
    adminSite('sqlite');
    $body = dispatch('/admin/design')->body;
    preg_match_all('~<p class="derived">(.*?)</p>~s', $body, $lines);
    $derived = implode(' ', $lines[1]);

    assertTrue(!str_contains($derived, 'clamp('), 'the screen printed a clamp()');
    assertTrue(!str_contains($derived, 'rem'), 'the screen printed rem values');
    $readable = Tokens::readable(Presets::get(Presets::DEFAULT));
    assertContains($readable['text']['base'] . 'px', $derived, 'the body size');
    assertContains($readable['radius'] . 'px', $derived, 'the corner radius');
    assertContains($readable['container'] . 'px', $derived, 'the content width');
});

test('Save stands beside the preview and still submits the form', function () {
    adminSite('sqlite');
    $body = dispatch('/admin/design')->body;

    // In the preview's own bar, which is the one part of a sticky column that is always in
    // view: below a frame 78vh tall, a button never comes back into reach however far the
    // page is scrolled.
    preg_match('~<div class="preview-bar">(.*?)</div>\s*</div>~s', $body, $bar);
    assertContains('value="save"', $bar[1] ?? '', 'Save stands in the preview bar');
    assertContains('<button type="submit" form="design-form" name="action" value="save"', $body, 'the button names its form');
    assertContains('id="design-form"', $body, 'the form it names');

    // And outside the form element, which is the whole point: it must not be a child of it.
    preg_match('~<form id="design-form".*?</form>~s', $body, $form);
    assertTrue(!str_contains($form[0] ?? '', 'value="save"'), 'the button is still inside the form');

    // And it still saves: the button is outside the form element, so this is not a detail
    // the markup alone can settle.
    $saved = adminPost('/admin/design', designFields(Presets::get('bold')) + ['action' => 'save']);
    assertRedirectedTo('/admin/design', $saved);
});
