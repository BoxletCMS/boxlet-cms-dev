<?php

// Every front-end page names its one canonical address (absolute, no query string).

testBothDrivers('canonical links for pages and home pages in both locales', function (string $driver) {
    $db = installedSite(['en' => 'English', 'hr' => 'Hrvatski'], $driver);
    createPage($db, 'en', 'about', 'About');
    createPage($db, 'hr', 'about', 'O nama');
    createPage($db, 'en', '', 'Home');
    createPage($db, 'hr', '', 'Početna');

    $expected = [
        '/about' => 'http://example.test/about',
        '/hr/about' => 'http://example.test/hr/about',
        '/' => 'http://example.test/',
        '/hr/' => 'http://example.test/hr/',
        '/about?utm_source=newsletter' => 'http://example.test/about',
    ];
    foreach ($expected as $path => $canonical) {
        $response = dispatch($path);
        assertEquals(200, $response->status, "{$path} status");
        assertContains('<link rel="canonical" href="' . $canonical . '">', $response->body, $path);
        assertEquals(1, substr_count($response->body, 'rel="canonical"'), "{$path} canonical count");
    }
});

test('the redirecting variant points to the address whose canonical it is', function () {
    $db = installedSite(['en' => 'English', 'hr' => 'Hrvatski']);
    createPage($db, 'en', 'about', 'About');

    assertEquals('/about', dispatch('/en/about')->headers['Location'] ?? null, '/en/about redirect');
    assertContains('<link rel="canonical" href="http://example.test/about">', dispatch('/about')->body, 'target');
});

test('a 404 page has no canonical link', function () {
    installedSite(['en' => 'English']);

    assertTrue(!str_contains(dispatch('/missing')->body, 'rel="canonical"'), '404 page has a canonical link');
});
