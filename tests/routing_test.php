<?php

use App\Core\Db;
use App\Core\Response;

// The SPEC §5.1 routing table. Every test installs its own site with primary "en" and
// "hr" enabled and its pages created explicitly, never relying on config or defaults.

function routingSite(): Db
{
    $db = installedSite(['en' => 'English', 'hr' => 'Hrvatski']);
    foreach (['en' => ['Hello', 'Home'], 'hr' => ['Bok', 'Početna']] as $locale => [$hello, $home]) {
        createPage($db, $locale, 'hello', $hello, true, [['type' => 'text', 'content' => ['body' => "<p>{$hello}</p>"]]]);
        createPage($db, $locale, '', $home);
    }

    return $db;
}

function assertPage(Response $response, string $lang, int $status): void
{
    assertEquals($status, $response->status, 'status');
    assertEquals(null, $response->headers['Location'] ?? null, 'Location header');
    assertContains('<html lang="' . $lang . '">', $response->body, 'body');
}

foreach (['/hello' => 'en', '/hr/hello' => 'hr', '/' => 'en', '/hr/' => 'hr'] as $path => $lang) {
    test("{$path} → 200 lang={$lang}", function () use ($path, $lang) {
        routingSite();
        assertPage(dispatch($path), $lang, 200);
    });
}

$redirects = [
    '/en/hello' => '/hello',
    '/en/' => '/',
    '/en' => '/',
    '/hr' => '/hr/',
];
foreach ($redirects as $from => $to) {
    test("{$from} → 301 {$to}", function () use ($from, $to) {
        routingSite();
        $response = dispatch($from);
        assertEquals(301, $response->status, 'status');
        assertEquals($to, $response->headers['Location'] ?? null, 'Location header');
    });
}

// The real 404 page, rendered in the locale the path resolved to.
foreach (['/de/hello' => 'en', '/go' => 'en', '/nope' => 'en', '/hr/nope' => 'hr'] as $path => $lang) {
    test("{$path} → 404 lang={$lang}", function () use ($path, $lang) {
        routingSite();
        $response = dispatch($path);
        assertPage($response, $lang, 404);
        assertContains('<p class="meta">404</p>', $response->body, 'body');
    });
}

// /go is not a locale, so it reaches the page lookup as slug "go": create that page and
// it answers. If locale parsing had swallowed it, the page could never be reached.
test('/go is a page lookup, not locale detection', function () {
    $db = routingSite();
    createPage($db, 'en', 'go', 'Go');
    assertPage(dispatch('/go'), 'en', 200);
});

test('/en is locale detection, even if a page somehow has the slug "en"', function () {
    $db = routingSite();
    // Slug validation forbids this; insert directly to prove routing wins regardless.
    $db->query(
        'INSERT INTO pages (locale, slug, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
        ['en', 'en', 'En', 'published', '2026-01-01 00:00:00', '2026-01-01 00:00:00'],
    );
    assertEquals(301, dispatch('/en')->status, 'status');
});

// Managed nginx setups (CloudPanel among them) answer any URL ending in a static-file
// extension from disk and never pass a miss to PHP, so such a route would 404 there.
test('no route path ends in a file extension', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/app/bootstrap.php');
    preg_match_all("~->(?:get|post)\('([^']+)'~", $source, $routes);

    assertTrue(count($routes[1]) > 10, 'routes not found in bootstrap.php');
    foreach ($routes[1] as $path) {
        assertTrue(!preg_match('~\.[A-Za-z0-9]+$~', $path), "route {$path} ends in a file extension");
    }
});

test('a locale that is not enabled in the database is not a locale', function () {
    $db = installedSite(['en' => 'English']);
    createPage($db, 'en', 'hello', 'Hello');
    assertEquals(404, dispatch('/hr/hello')->status, '/hr/hello with only en enabled');
});
