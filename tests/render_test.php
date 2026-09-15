<?php

// Front-end rendering of pages and their blocks, against both drivers.

testBothDrivers('a published page renders its blocks in order', function (string $driver) {
    $db = installedSite(['en' => 'English', 'hr' => 'Hrvatski'], $driver);
    createPage($db, 'en', 'about', 'About us', true, [
        ['type' => 'hero', 'content' => ['heading' => 'Welcome']],
        ['type' => 'text', 'content' => ['body' => '<p>Second block</p>']],
    ]);
    $response = dispatch('/about');

    assertEquals(200, $response->status, 'status');
    assertContains('<title>About us</title>', $response->body, 'title');
    $hero = strpos($response->body, 'block-hero');
    $text = strpos($response->body, 'block-text');
    assertTrue($hero !== false && $text !== false && $hero < $text, 'the hero does not render before the text');
    assertContains('Welcome', $response->body, 'hero heading');
    assertContains('<p>Second block</p>', $response->body, 'text body');
});

testBothDrivers('an unpublished page is a 404 for visitors', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    createPage($db, 'en', 'secret', 'Secret', false, [['type' => 'hero', 'content' => ['heading' => 'Not yet']]]);
    $response = dispatch('/secret');

    assertEquals(404, $response->status, 'status');
    assertTrue(!str_contains($response->body, 'Not yet'), 'unpublished content leaked');
});

testBothDrivers('home pages resolve at / and at /hr/', function (string $driver) {
    $db = installedSite(['en' => 'English', 'hr' => 'Hrvatski'], $driver);
    createPage($db, 'en', '', 'Home', true, [['type' => 'hero', 'content' => ['heading' => 'Hello visitor']]]);
    createPage($db, 'hr', '', 'Početna', true, [['type' => 'hero', 'content' => ['heading' => 'Bok posjetitelju']]]);

    foreach (['/' => ['en', 'Hello visitor'], '/hr/' => ['hr', 'Bok posjetitelju']] as $path => [$lang, $heading]) {
        $response = dispatch($path);
        assertEquals(200, $response->status, "{$path} status");
        assertContains('<html lang="' . $lang . '">', $response->body, $path);
        assertContains($heading, $response->body, $path);
    }
});

test('the locale switcher links each enabled locale to its home page', function () {
    $db = installedSite(['en' => 'English', 'hr' => 'Hrvatski']);
    createPage($db, 'en', 'about', 'About');
    $body = dispatch('/about')->body;

    assertContains('<a href="/" hreflang="en"', $body, 'English home');
    assertContains('<a href="/hr/" hreflang="hr"', $body, 'Croatian home');
});

test('a block whose type is no longer installed is skipped, not fatal', function () {
    $db = installedSite(['en' => 'English']);
    $id = createPage($db, 'en', 'about', 'About', true, [['type' => 'text', 'content' => ['body' => '<p>Still here</p>']]]);
    $db->query(
        'INSERT INTO page_blocks (page_id, block_type, sort, content_json, style_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$id, 'gone', 5, '{}', '{}', '2026-01-01 00:00:00', '2026-01-01 00:00:00'],
    );
    $response = dispatch('/about');

    assertEquals(200, $response->status, 'status');
    assertContains('<p>Still here</p>', $response->body, 'remaining block');
});
