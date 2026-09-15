<?php

use App\Support\RichText;
use App\Support\SafeUrl;

// The richtext whitelist and the link URL rule (SPEC §5.3).

$cases = [
    'allowed markup is kept' => ['<p>Hello <strong>world</strong> and <em>you</em></p>', '<p>Hello <strong>world</strong> and <em>you</em></p>'],
    'attributes are stripped' => ['<p onclick="steal()" class="big" style="color:red">Hi</p>', '<p>Hi</p>'],
    'script is removed with its content' => ['<script>alert(1)</script><p>After</p>', '<p>After</p>'],
    'unknown elements are unwrapped' => ['<div><span>Unwrapped</span> text</div>', 'Unwrapped text'],
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
