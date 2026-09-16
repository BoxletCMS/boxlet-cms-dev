<?php

use App\Support\RichText;
use App\Support\SafeUrl;

// The richtext whitelist and the link URL rule (SPEC §5.3).

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

// What Trix 2.1.19 actually emits, captured by driving it through the keyboard and the
// toolbar rather than through its API, then normalised into our storage format. These
// inputs are observations, not inventions: if Trix changes, these are what to re-capture.
$fromTrix = [
    'the div it wraps every block in becomes a paragraph'
        => ['<div>First line<br>Second line</div>', '<p>First line<br>Second line</p>'],
    'its single heading level is demoted, because the page title is the h1'
        => ['<h1>A heading</h1>', '<h2>A heading</h2>'],
    'a quote is already what we store'
        => ['<blockquote>A quotation</blockquote>', '<blockquote>A quotation</blockquote>'],
    'a link made through the toolbar dialog'
        => ['<div><a href="https://example.com/page">this page</a></div>', '<p><a href="https://example.com/page">this page</a></p>'],
    'bullets nested three deep keep their structure'
        => [
            '<ul><li>One<ul><li>Two<ul><li>Three</li></ul></li></ul></li><li>Back out</li></ul>',
            '<ul><li>One<ul><li>Two<ul><li>Three</li></ul></li></ul></li><li>Back out</li></ul>',
        ],
    'a numbered list'
        => ['<ol><li>Step one</li><li>Step two</li></ol>', '<ol><li>Step one</li><li>Step two</li></ol>'],
    // The whole Word paste, as Trix reduced it. Everything mso-, every inline style, the
    // font tag and the table were already gone before our sanitiser saw it.
    'a passage pasted from a word processor'
        => [
            '<div><strong>Bold lead-in</strong> and <em>italic</em> text.</div>'
            . '<div><br>Second paragraph with a <a href="https://example.com/x?a=1">link</a>.<br><br></div>'
            . '<ul><li>First</li><li>Second<ul><li>Nested</li></ul></li></ul>'
            . '<div>Coloured font tag</div><div>Cell</div>',
            '<p><strong>Bold lead-in</strong> and <em>italic</em> text.</p>'
            . '<p><br>Second paragraph with a <a href="https://example.com/x?a=1">link</a>.<br><br></p>'
            . '<ul><li>First</li><li>Second<ul><li>Nested</li></ul></li></ul>'
            . '<p>Coloured font tag</p><p>Cell</p>',
        ],
];
foreach ($fromTrix as $name => [$input, $expected]) {
    test("richtext from Trix: {$name}", function () use ($input, $expected) {
        assertEquals($expected, RichText::sanitize($input), 'normalised');
    });
}

// A p may not contain a list. Renaming every div regardless would produce invalid markup
// that we generated ourselves, so a div holding a block is unwrapped as before.
$structure = [
    'a div holding a list is unwrapped, not renamed' => ['<div><ul><li>Item</li></ul></div>', '<ul><li>Item</li></ul>'],
    'a div holding text and a list is unwrapped too' => ['<div>Lead<ul><li>Item</li></ul></div>', 'Lead<ul><li>Item</li></ul>'],
    'nested divs collapse to one paragraph' => ['<div><div>Inner</div></div>', '<p>Inner</p>'],
    'headings below h3 collapse to the deepest we allow' => ['<h4>Sub</h4><h6>Deeper</h6>', '<h3>Sub</h3><h3>Deeper</h3>'],
];
foreach ($structure as $name => [$input, $expected]) {
    test("richtext structure: {$name}", function () use ($input, $expected) {
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
// would rewrite content nobody edited, and each revision would differ from the last for
// no reason — a difference invisible to the eye and permanent in the history.
//
// This proves the sanitiser does not change its mind. It says nothing about what Trix
// returns when given already-clean content: that needs a browser, and is checked there.
test('sanitising is idempotent, so an untouched save rewrites nothing', function () use ($fromTrix, $structure, $attachments) {
    $inputs = array_merge(
        array_column(array_values($fromTrix), 0),
        array_column(array_values($structure), 0),
        array_column(array_values($attachments), 0),
        [
            // The non-breaking space Trix emits for a trailing space. Asserting its exact
            // output would mean an invisible character in this file, so it is pinned by
            // idempotence rather than by equality.
            '<div><strong>bold</strong> plain' . "\u{00A0}" . '<em>italic</em></div>',
            '<p>Existing demo copy with a <a href="/about">link</a>.</p>',
            '<blockquote>They understood what we wanted.</blockquote><p>Ana, a baker</p>',
        ],
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
