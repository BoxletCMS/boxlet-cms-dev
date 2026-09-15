<?php

use App\Core\Response;
use App\Core\Router;
use App\Modules\Pages\PageController;

// The SPEC §5.1 routing table, with primary "en" and "hr" enabled. Every case runs
// with pretty URLs and with the index.php?route= fallback.

test('precondition: config has primary en and enabled en, hr', function () {
    $locales = require dirname(__DIR__) . '/config/locales.php';
    assertEquals('en', $locales['primary'], 'locales.primary');
    assertEquals(['en', 'hr'], array_column($locales['enabled'], 'code'), 'enabled locale codes');
});

function assertPage(Response $response, string $lang, int $status): void
{
    assertEquals($status, $response->status, 'status');
    assertEquals(null, $response->headers['Location'] ?? null, 'Location header');
    assertContains('<html lang="' . $lang . '">', $response->body, 'body');
}

foreach (['/hello' => 'en', '/hr/hello' => 'hr'] as $path => $lang) {
    testBothModes("{$path} → 200 lang={$lang}", function (bool $pretty) use ($path, $lang) {
        assertPage(dispatch($path, $pretty), $lang, 200);
    });
}

$redirects = [
    '/en/hello' => '/hello',
    '/en/' => '/',
    '/en' => '/',
    '/hr' => '/hr/',
];
foreach ($redirects as $from => $to) {
    testBothModes("{$from} → 301 {$to}", function (bool $pretty) use ($from, $to) {
        $response = dispatch($from, $pretty);
        assertEquals(301, $response->status, 'status');
        assertEquals(expectedUrl($to, $pretty), $response->headers['Location'] ?? null, 'Location header');
    });
}

// The real 404 page, rendered in the locale the path resolved to. / and /hr/ are 404
// only until Slice 3 adds a home route.
$notFound = [
    '/de/hello' => 'en',
    '/go' => 'en',
    '/' => 'en',
    '/hr/' => 'hr',
    '/nope' => 'en',
    '/hr/nope' => 'hr',
];
foreach ($notFound as $path => $lang) {
    testBothModes("{$path} → 404 lang={$lang}", function (bool $pretty) use ($path, $lang) {
        $response = dispatch($path, $pretty);
        assertPage($response, $lang, 404);
        assertContains('<p class="meta">404</p>', $response->body, 'body');
    });
}

// /go is not a locale, so it reaches the page routes as slug "go". Proven by giving it
// a route: if locale parsing swallowed it, the route could never answer.
testBothModes('/go is a page lookup, not locale detection', function (bool $pretty) {
    $addRoute = fn (Router $router) => $router->get('/go', [PageController::class, 'hello']);
    assertPage(dispatch('/go', $pretty, $addRoute), 'en', 200);
});

// Same shape as /go, but enabled: detected as a locale, so a /en route never answers.
testBothModes('/en is locale detection, not a page lookup', function (bool $pretty) {
    $addRoute = fn (Router $router) => $router->get('/en', [PageController::class, 'hello']);
    assertEquals(301, dispatch('/en', $pretty, $addRoute)->status, 'status');
});
