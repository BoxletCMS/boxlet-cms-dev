<?php

use App\Core\Container;
use App\Core\Settings;
use App\Modules\Design\Composition;
use App\Modules\Pages\PageLayoutData;
use App\Modules\Settings\ChromeLook;

// ONE SOURCE FOR THE SITE LAYOUT'S VARIABLES (PLAN.md D-057). Two renderers draw that
// layout — a visitor's page and the admin's design preview — and the second was caught out
// three times by a variable it did not know about: `description`, then `icon`, then the
// chrome of 5c. The suite could not catch any of them on its own, because it only fails on
// the branch some test happens to render and `$icon` sits behind an `if`.
//
// So the first test here does not render anything: it READS THE TEMPLATE and compares the
// variables it uses with PageLayoutData::KEYS. adminSite() comes from pages_admin_test.php.

/**
 * Every variable app/Modules/Pages/views/layout.php READS, as opposed to every variable it
 * mentions: a foreach's own value, and anything the template assigns, are its own business.
 *
 * @return list<string>
 */
function layoutVariables(): array
{
    $tokens = token_get_all((string) file_get_contents(dirname(__DIR__) . '/app/Modules/Pages/views/layout.php'));
    /** @var list<array{int, string, int}|string> $code */
    $code = array_values(array_filter($tokens, static fn ($token): bool
        => is_string($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_INLINE_HTML], true)));

    $used = [];
    $bound = [];
    $count = count($code);
    for ($i = 0; $i < $count; $i++) {
        $token = $code[$i];
        if (is_array($token) && $token[0] === T_AS) {
            // foreach (… as $key => $value): both sides are the loop's own.
            for ($j = $i + 1; $j < $count && $code[$j] !== ')'; $j++) {
                if (is_array($code[$j]) && $code[$j][0] === T_VARIABLE) {
                    $bound[] = substr($code[$j][1], 1);
                }
            }
            continue;
        }
        if (!is_array($token) || $token[0] !== T_VARIABLE) {
            continue;
        }
        $name = substr($token[1], 1);
        if (($code[$i + 1] ?? null) === '=') {
            $bound[] = $name;
            continue;
        }
        $used[] = $name;
    }

    // $locale and $content are View's own, handed to every template it renders.
    return array_values(array_diff(array_unique($used), $bound, ['locale', 'content']));
}

test('the layout reads exactly the variables PageLayoutData declares', function () {
    $used = layoutVariables();
    sort($used);
    $keys = PageLayoutData::KEYS;
    sort($keys);

    assertEquals($keys, $used, 'layout.php against PageLayoutData::KEYS');
});

test('the layout pulls in no other template that could read a variable of its own', function () {
    $tokens = token_get_all((string) file_get_contents(dirname(__DIR__) . '/app/Modules/Pages/views/layout.php'));
    foreach ($tokens as $token) {
        if (is_array($token) && in_array($token[0], [T_INCLUDE, T_REQUIRE, T_REQUIRE_ONCE], true)) {
            fail('layout.php includes another file, so the scan above no longer sees everything it reads.');
        }
    }
});

testBothDrivers('both factories answer with the whole set, for a page and for a preview', function (string $driver) {
    $db = adminSite($driver);
    lookSite($db);

    // Inside a real request, because these read the container the request builds — and a
    // key MISSING from one of them is the failure this whole file exists for.
    $keys = [];
    dispatch('/', null, 'GET', [], '203.0.113.10', static function (Container $container) use (&$keys): void {
        $keys['forPage'] = array_keys(PageLayoutData::forPage($container, 'en', ['title' => 'Home']));
        $keys['forPreview'] = array_keys(PageLayoutData::forPreview($container, 'en', 'Preview'));
    });

    assertEquals(PageLayoutData::KEYS, $keys['forPage'] ?? [], 'forPage');
    assertEquals(PageLayoutData::KEYS, $keys['forPreview'] ?? [], 'forPreview');
});

// The three historical misses, as assertions on the served preview.

test('the preview draws the site header and footer, its icon, and no description', function () {
    $db = adminSite('sqlite');
    lookSite($db);
    $body = dispatch('/admin/appearance/preview')->body;

    assertContains('<header class="', $body, 'the header the old preview left out');
    assertContains('<footer class="', $body, 'the footer');
    assertContains('>About<', $body, 'the menu inside it');
    assertTrue(!str_contains($body, '<meta name="description"'), 'a preview describes no page (D-004)');
});

test('the preview and a real page agree about the tab icon', function () {
    [$storage, $public] = mediaPaths();
    $db = adminSite('sqlite');
    lookSite($db);
    Settings::set($db, 'site_favicon', chromePicture($db, $storage, $public, 'icon'));

    assertContains('rel="icon"', dispatch('/')->body, 'a real page');
    assertContains('rel="icon"', dispatch('/admin/appearance/preview')->body, 'the preview');
});

test('the preview draws chrome choices the request is trying and writes none of them', function () {
    $db = adminSite('sqlite');
    lookSite($db);
    Composition::remember($db, 'minimal');
    $before = ChromeLook::stored($db);

    $body = dispatch('/admin/appearance/preview?look_header_surface=contrast&look_density=roomy')->body;

    assertContains('surface-contrast', headerTag($body), 'the surface being tried');
    assertContains('density-roomy', $body, 'the density being tried');
    assertEquals($before, ChromeLook::stored($db), 'nothing was written');
});

test('a look value outside its closed set is not drawn', function () {
    $db = adminSite('sqlite');
    lookSite($db);
    Composition::remember($db, 'minimal');

    $body = dispatch('/admin/appearance/preview?look_header_surface=neon')->body;

    assertTrue(!str_contains($body, 'surface-neon'), 'an invented surface was drawn');
    assertContains('surface-plain', headerTag($body), 'Minimal\'s own, which it falls back to');
});

test('the chrome follows the character being previewed, not the one the site is published with', function () {
    $db = adminSite('sqlite');
    lookSite($db);
    Composition::remember($db, 'minimal');

    $body = dispatch('/admin/appearance/preview?character=brutalist')->body;

    assertContains('layout-split', headerTag($body), 'Brutalist\'s arrangement');
    assertContains('density-compact', $body, 'Brutalist\'s density');
});

test('a choice the owner saved survives a preview that says nothing about it', function () {
    $db = adminSite('sqlite');
    lookSite($db);
    Composition::remember($db, 'minimal');
    ChromeLook::save($db, ['header_surface' => 'tinted']);

    $body = dispatch('/admin/appearance/preview')->body;

    assertContains('surface-tinted', headerTag($body), 'the owner\'s saved surface');
});
