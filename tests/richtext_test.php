<?php

use App\Support\RichText;
use App\Support\SafeUrl;

// The richtext whitelist and the link URL rule (SPEC §5.3): what may be stored at all.
//
// What an allowed block then looks like — renames, edge breaks, the paragraphs an editor
// wraps list items and quotes in — is richtext_shape_test.php, and the guards over the
// editor itself are richtext_editor_test.php. One file held all three until it passed the
// size limit. Nothing changed in the split.

$cases = [
    'allowed markup is kept' => ['<p>Hello <strong>world</strong> and <em>you</em></p>', '<p>Hello <strong>world</strong> and <em>you</em></p>'],
    'attributes are stripped' => ['<p onclick="steal()" class="big" style="color:red">Hi</p>', '<p>Hi</p>'],
    'script is removed with its content' => ['<script>alert(1)</script><p>After</p>', '<p>After</p>'],
    'unknown inline elements are unwrapped' => ['<p><span>Unwrapped</span> text</p>', '<p>Unwrapped text</p>'],
    'images and iframes are removed' => ['<img src="x" onerror="alert(1)"><iframe src="https://example.com"></iframe>ok', 'ok'],
    'comments are removed' => ['<!-- secret --><p>x</p>', '<p>x</p>'],
    'javascript: links lose their href' => ['<a href="javascript:alert(1)">x</a>', '<a>x</a>'],
    'an obfuscated scheme loses its href' => ['<a href="java&#x09;script:alert(1)">x</a>', '<a>x</a>'],
    'safe links keep only href' => ['<a href="https://example.com/a?b=1" target="_blank" rel="x">x</a>', '<a href="https://example.com/a?b=1">x</a>'],
    'site-relative links are kept' => ['<a href="/about">About</a>', '<a href="/about">About</a>'],
    'unclosed tags are closed' => ['<p>unclosed <strong>bold', '<p>unclosed <strong>bold</strong></p>'],
    'text is escaped' => ['5 < 6 & "quoted"', '5 &lt; 6 &amp; "quoted"'],
    'non-ASCII text survives' => ['<p>Čćđšž — 日本語</p>', '<p>Čćđšž — 日本語</p>'],
    'blank input stays empty' => ['   ', ''],
];
foreach ($cases as $name => [$input, $expected]) {
    test("richtext: {$name}", function () use ($input, $expected) {
        assertEquals($expected, RichText::sanitize($input), 'sanitized');
    });
}

// Attachments are disabled in the editor. This is the backstop, so a later version of it
// cannot reintroduce them silently. Removed with their content: the insides are the
// editor's markup, not the author's words.
$attachments = [
    'a figure carrying attachment JSON is removed whole'
        => ['<figure data-trix-attachment=\'{"url":"/x.png"}\'><img src="/x.png"><figcaption>Caption</figcaption></figure><div>After</div>', '<p>After</p>'],
    'any element carrying the attachment attribute goes with its content'
        => ['<div data-trix-attachment="{}">Attachment innards</div><div>Kept</div>', '<p>Kept</p>'],
    'a bare figure is removed even without the attribute'
        => ['<figure><img src="/x.png"></figure><div>Kept</div>', '<p>Kept</p>'],
];
foreach ($attachments as $name => [$input, $expected]) {
    test("richtext attachments: {$name}", function () use ($input, $expected) {
        assertEquals($expected, RichText::sanitize($input), 'sanitized');
    });
}

// Sanitising twice must give the same thing as sanitising once. Without this, every save
// would rewrite content nobody edited, and each revision would differ from the last for no
// reason — a difference invisible to the eye and permanent in the history.
//
// One of two: this covers the whitelist inputs in this file, and richtext_shape_test.php
// covers the block shapes in that one. They were a single test before the split, over both
// sets; keeping each with its own inputs is what lets either file be read on its own.
test('sanitising is idempotent for everything the whitelist decides', function () use ($cases, $attachments) {
    $inputs = array_merge(
        array_column(array_values($cases), 0),
        array_column(array_values($attachments), 0),
    );

    foreach ($inputs as $input) {
        $once = RichText::sanitize($input);
        assertEquals($once, RichText::sanitize($once), 'second pass changed: ' . $once);
    }
});

test('link URLs: only site-relative, http(s), mailto and tel', function () {
    $urls = [
        '/about' => true,
        '#top' => true,
        '?page=2' => true,
        'https://example.com/a' => true,
        'HTTP://EXAMPLE.COM' => true,
        'mailto:hello@example.com' => true,
        'tel:+385123456' => true,
        'javascript:alert(1)' => false,
        'JavaScript:alert(1)' => false,
        "java\tscript:alert(1)" => false,
        'data:text/html,x' => false,
        '//evil.example' => false,
        '/\\evil.example' => false,
        'about' => false,
        'https://example.com/a b' => false,
        '' => false,
    ];
    foreach ($urls as $url => $allowed) {
        assertEquals($allowed, SafeUrl::isAllowed((string) $url), (string) json_encode((string) $url));
    }
});
