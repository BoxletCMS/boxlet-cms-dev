<?php

use App\Core\Response;
use App\Core\Session;
use App\Modules\Settings\LogoSvg;

// An SVG logo (PLAN.md D-142), through the router: uploaded under Branding, cleaned, drawn by
// the header in place of the picture in its slot, and taken away again. The media library
// still refuses an SVG. The file an upload writes is the cleaned one, never the upload.
// svg_sanitizer_test.php has every attack; this file has the way in and the way out.

const LOGO_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 160 40" onload="alert(1)"><script>alert(2)</script><path d="M0 0h40v40H0z" fill="#123456"/></svg>';

/**
 * Posts an upload as PHP hands one over, from a real temporary file. is_uploaded_file() is
 * the one thing a test cannot make true, so the controller is reached with that check
 * standing in for the upload: the file is written where move_uploaded_file() would read it.
 *
 * @param array<string, string> $fields
 */
function logoPost(array $fields, ?string $svg = null): Response
{
    $files = [];
    if ($svg !== null) {
        $tmp = tmpPath('upload-' . bin2hex(random_bytes(4)) . '.svg');
        file_put_contents($tmp, $svg);
        $files = ['svg' => ['name' => 'logo.svg', 'type' => 'image/svg+xml', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($svg)]];
    }
    $saved = $_FILES;
    $_FILES = $files;
    try {
        return dispatch('/admin/settings/logo-svg', null, 'POST', ['_csrf' => (new Session())->csrfToken()] + $fields);
    } finally {
        $_FILES = $saved;
    }
}

test('an upload that is not a real upload is refused, and nothing is stored', function () {
    $db = adminSite('sqlite');
    // PHP's own check: a path named in $_FILES that PHP did not receive is not taken.
    assertRedirectedTo('/admin/settings#branding', logoPost(['slot' => 'logo'], LOGO_SVG));
    assertEquals(t('svg.no_file'), $_SESSION['flash'] ?? null, 'told');
    assertEquals(null, LogoSvg::get($db, 'logo'), 'stored anyway');
});

test('a cleaned logo is stored, drawn by the header as an <img>, and removed again', function () {
    $db = adminSite('sqlite');
    createPage($db, 'en', 'about', 'About');
    $public = (string) TestSite::$env['PUBLIC_PATH'];
    removeTree($public . '/m/logo');

    $clean = App\Support\SvgSanitizer::clean(LOGO_SVG);
    LogoSvg::store($db, $public, 'logo', $clean['svg'], $clean['width'], $clean['height']);
    $logo = LogoSvg::get($db, 'logo') ?? fail('not stored');
    assertEquals([160, 40], [$logo['width'], $logo['height']], 'size');
    $file = $public . '/m/logo/' . basename($logo['url']);
    $written = (string) file_get_contents($file);
    assertTrue(!str_contains($written, 'alert') && !str_contains($written, 'onload'), 'the upload was stored as it came');
    assertContains('fill="#123456"', $written, 'the drawing');

    $page = dispatch('/about')->body;
    assertContains('<img class="site-logo-svg" src="' . $logo['url'] . '" width="160" height="40"', $page, 'the header draws it as an image');
    assertTrue(!str_contains($page, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 160 40"'), 'drawn inline, where a script would run');

    // A second upload in the same slot replaces the first, whose file goes.
    $other = App\Support\SvgSanitizer::clean('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>');
    LogoSvg::store($db, $public, 'logo', $other['svg'], $other['width'], $other['height']);
    assertTrue(!is_file($file), 'the replaced file was left behind');

    assertRedirectedTo('/admin/settings#branding', logoPost(['slot' => 'logo', 'action' => 'remove']));
    assertEquals(null, LogoSvg::get($db, 'logo'), 'removed');
    assertEquals([], glob($public . '/m/logo/*.svg') ?: [], 'its file left behind');
    assertTrue(!str_contains(dispatch('/about')->body, 'site-logo-svg'), 'still drawn');
});

test('the SVG for dark surfaces is its own slot', function () {
    $db = adminSite('sqlite');
    $public = (string) TestSite::$env['PUBLIC_PATH'];
    $clean = App\Support\SvgSanitizer::clean(LOGO_SVG);
    LogoSvg::store($db, $public, 'logo_dark', $clean['svg'], $clean['width'], $clean['height']);
    assertEquals(null, LogoSvg::get($db, 'logo'), 'the site\'s slot');
    assertTrue(LogoSvg::get($db, 'logo_dark') !== null, 'the dark slot');
    LogoSvg::remove($db, $public, 'logo_dark');
    removeTree($public . '/m/logo');
});

test('the media library still refuses an SVG', function () {
    assertEquals(null, App\Modules\Media\MediaFileType::extensionFor('logo.svg', 'image/svg+xml'), 'an SVG in the library');
});

test('Settings shows where an SVG logo goes, under the pictures it stands in for', function () {
    adminSite('sqlite');
    $body = dispatch('/admin/settings')->body;
    assertContains('id="logo-svg-logo"', $body, 'the site logo\'s form');
    assertContains('id="logo-svg-logo_dark"', $body, 'the dark logo\'s form');
    assertContains('form="logo-svg-logo"', $body, 'its controls');
    assertContains('enctype="multipart/form-data"', $body, 'a form that carries a file');
});
