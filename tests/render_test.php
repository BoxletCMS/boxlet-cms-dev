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

// Lives here rather than with the other picture tests because it is a fact about a PAGE,
// not about one picture: only a real render knows which section came first.
testBothDrivers('the first section loads eagerly, the rest lazily', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $top = storedPicture($db, 'abovefold', [
        'hero' => ['width' => 1920, 'height' => 1080, 'formats' => ['avif', 'jpg']],
    ]);
    $below = storedPicture($db, 'belowfold', [
        'card' => ['width' => 600, 'height' => 400, 'formats' => ['avif', 'jpg']],
    ]);
    createPage($db, 'en', 'gallery', 'Gallery', true, [
        ['type' => 'hero', 'content' => ['heading' => 'Top', 'image' => $top], 'layout' => 'split'],
        ['type' => 'image_text', 'content' => ['image' => $below, 'body' => '<p>Below</p>']],
    ]);

    $body = dispatch('/gallery')->body;

    // Guarded rather than asserted: a pattern that matches nothing should say so here,
    // not fail two lines later on a missing offset.
    if (preg_match('~<img[^>]*abovefold[^>]*>~', $body, $first) !== 1) {
        fail('the first section rendered no picture at all');
    }
    if (preg_match('~<img[^>]*belowfold[^>]*>~', $body, $rest) !== 1) {
        fail('the second section rendered no picture at all');
    }

    // Deferring the largest picture above the fold is the one case lazy loading hurts.
    assertTrue(!str_contains($first[0], 'loading="lazy"'), 'the picture above the fold was deferred');
    assertTrue(str_contains($rest[0], 'loading="lazy"'), 'a picture below the fold loads eagerly');
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
