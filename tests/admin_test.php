<?php

use App\Support\Url;

// The admin's design system is fixed and independent (SPEC §5.4): the site's compiled
// tokens never reach it, so the tool stays readable whatever the site is set to.

/**
 * @return list<string> the admin stylesheets, by file name
 */
function adminStylesheets(): array
{
    return [
        'admin.css', 'admin-ui.css', 'admin-forms.css', 'admin-pages.css', 'admin-design.css',
        // The editor's chrome. canvas.css matters most: it is the one admin stylesheet
        // loaded into a document full of the site's tokens, so a selection outline that
        // borrowed one would be unreadable on the designs that need it most.
        'builder.css', 'canvas.css',
    ];
}

test('no admin stylesheet reads a token from the site\'s design', function () {
    // Every group tokens.css defines. The admin declares its own values, --ui-* for the
    // chrome around the canvas and --bx-* for the chrome inside it.
    $siteTokens = '~var\(\s*--(color|space|text|font|heading|body|leading|radius|shadow|border|container|section|divider)-~';

    foreach (adminStylesheets() as $css) {
        $source = (string) file_get_contents(dirname(__DIR__) . '/public/assets/' . $css);
        assertTrue(!preg_match($siteTokens, $source, $match), "{$css} reads the site token " . ($match[0] ?? ''));
        assertTrue(
            str_contains($source, '--ui-') || str_contains($source, '--bx-'),
            "{$css} defines or uses no admin token of its own",
        );
    }
});

test('no admin screen links the site stylesheet', function () {
    $db = adminSite('sqlite');
    $id = createPage($db, 'en', 'about', 'About');

    foreach (['/admin', '/admin/pages', '/admin/pages/new', "/admin/pages/{$id}", '/admin/design'] as $path) {
        $body = dispatch($path)->body;
        assertTrue(!str_contains($body, '/cache/tokens.'), "{$path} links the site stylesheet");
        assertContains('assets/admin.css', $body, $path);
    }

    // The login screen, with no session at all.
    unset($_SESSION['admin_id']);
    $login = dispatch('/admin/login')->body;
    assertTrue(!str_contains($login, '/cache/tokens.'), '/admin/login links the site stylesheet');
    assertContains('assets/admin.css', $login, '/admin/login');
});

test('the admin renders identically whatever the site design is', function () {
    $db = adminSite('sqlite');
    createPage($db, 'en', 'about', 'About');
    $chrome = static function (): string {
        $body = dispatch('/admin/pages')->body;
        // Everything but the cache-busting version of the admin's own assets.
        return (string) preg_replace('~\?v=[0-9a-f]+~', '', $body);
    };

    adminPost('/admin/design', designFields(\App\Modules\Design\Presets::get('editorial')) + ['action' => 'save']);
    $underEditorial = $chrome();
    adminPost('/admin/design', designFields(\App\Modules\Design\Presets::get('brutalist')) + ['action' => 'save']);

    assertEquals($underEditorial, $chrome(), 'the admin changed with the site design');
});

test('a colour input is a real swatch carrying its value, not an empty box', function () {
    adminSite('sqlite');
    $body = dispatch('/admin/design')->body;

    assertContains('<input type="color" class="colour-input" id="design-seed" name="seed" value="#', $body, 'seed input');
    assertContains('data-colour-for="design-seed"', $body, 'the readable hex beside it');
});

// Cache busting for the stylesheets that are real files on disk (SPEC §5.4).

test('site.css and sections.css are linked with a hash of their content', function () {
    $db = installedSite(['en' => 'English']);
    createPage($db, 'en', 'about', 'About');
    $body = dispatch('/about')->body;

    foreach (['site.css', 'sections.css'] as $css) {
        $hash = substr((string) hash_file('sha256', dirname(__DIR__) . '/public/assets/' . $css), 0, 12);
        assertContains("/assets/{$css}?v={$hash}", $body, "{$css} link");
    }
});

test('a versioned URL changes only when the file does', function () {
    $dir = tmpPath('public');
    removeTree($dir);
    mkdir($dir . '/assets', 0700, true);
    file_put_contents($dir . '/assets/one.css', 'a{}');
    file_put_contents($dir . '/assets/two.css', 'b{}');
    Url::usePublicPath($dir);

    $first = Url::versioned('assets/one.css');
    assertEquals($first, Url::versioned('assets/one.css'), 'the same file gives the same URL');
    assertTrue($first !== Url::versioned('assets/two.css'), 'different files share a URL');
    assertTrue((bool) preg_match('~^/assets/one\.css\?v=[0-9a-f]{12}$~', $first), "URL shape: {$first}");

    file_put_contents($dir . '/assets/one.css', 'a{color:red}');
    assertTrue($first !== Url::versioned('assets/one.css'), 'the URL survived an edit to the file');

    assertEquals('/assets/missing.css', Url::versioned('assets/missing.css'), 'a missing file still gets a usable URL');
    removeTree($dir);
    Url::usePublicPath(dirname(__DIR__) . '/public');
});
