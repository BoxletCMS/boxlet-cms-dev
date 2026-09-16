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

// What a rich text editor actually emits, captured by driving one through the keyboard and
// the toolbar rather than through its API, then normalised into our storage format. These
// inputs are observations, not inventions. They were captured from the editor of the day
// and still hold: the sanitiser has to take this markup from whatever produces it, which
// is the point of it being the boundary rather than the editor.
$fromEditor = [
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
    // The whole Word paste, as the editor reduced it. Everything mso-, every inline style,
    // the font tag and the table were already gone before our sanitiser saw it. This case
    // is about that reduction; the breaks an editor leaves at a paragraph's edges are the
    // case below, so neither can hide a change in the other.
    'a passage pasted from a word processor'
        => [
            '<div><strong>Bold lead-in</strong> and <em>italic</em> text.</div>'
            . '<div><br>Second paragraph with a <a href="https://example.com/x?a=1">link</a>.<br><br></div>'
            . '<ul><li>First</li><li>Second<ul><li>Nested</li></ul></li></ul>'
            . '<div>Coloured font tag</div><div>Cell</div>',
            '<p><strong>Bold lead-in</strong> and <em>italic</em> text.</p>'
            . '<p>Second paragraph with a <a href="https://example.com/x?a=1">link</a>.</p>'
            . '<ul><li>First</li><li>Second<ul><li>Nested</li></ul></li></ul>'
            . '<p>Coloured font tag</p><p>Cell</p>',
        ],
    // Split out of the case above when D-014 changed the rule: it used to assert that the
    // edge breaks survived, which is the behaviour D-014 removes.
    'the breaks an editor leaves at a paragraph\'s edges are dropped'
        => [
            '<div><br>Second paragraph with a <a href="https://example.com/x?a=1">link</a>.<br><br></div>',
            '<p>Second paragraph with a <a href="https://example.com/x?a=1">link</a>.</p>',
        ],
];
foreach ($fromEditor as $name => [$input, $expected]) {
    test("richtext from the editor: {$name}", function () use ($input, $expected) {
        assertEquals($expected, RichText::sanitize($input), 'normalised');
    });
}

// A p may not contain a list. Renaming every div regardless would produce invalid markup
// that we generated ourselves, so a div holding a block is unwrapped as before.
$structure = [
    'a div holding a list is unwrapped, not renamed' => ['<div><ul><li>Item</li></ul></div>', '<ul><li>Item</li></ul>'],
    'a div holding text and a list is unwrapped too' => ['<div>Lead<ul><li>Item</li></ul></div>', 'Lead<ul><li>Item</li></ul>'],
    'nested divs collapse to one paragraph' => ['<div><div>Inner</div></div>', '<p>Inner</p>'],
    // Changed with D-016: h4 is stored now, so only h5 and h6 collapse, and they collapse
    // to h4 rather than h3. This case previously read h4-h6 all became h3.
    'h4 is stored; h5 and h6 collapse to it' => ['<h4>Sub</h4><h6>Deeper</h6>', '<h4>Sub</h4><h4>Deeper</h4>'],
    'h5 collapses to h4 as well' => ['<h5>Five</h5>', '<h4>Five</h4>'],
];
foreach ($structure as $name => [$input, $expected]) {
    test("richtext structure: {$name}", function () use ($input, $expected) {
        assertEquals($expected, RichText::sanitize($input), 'sanitized');
    });
}

// PLAN.md D-017. An editor whose schema wraps every list item in a paragraph would change
// how existing lists look: measured on the front end, <li><p>one</p></li> renders 16px
// taller per item than <li>one</li>. Storage keeps one shape whichever editor produced it.
$listItems = [
    'a paragraph that is the only block in an item is unwrapped'
        => ['<ul><li><p>one</p></li><li><p>two</p></li></ul>', '<ul><li>one</li><li>two</li></ul>'],
    'two paragraphs in one item are the author\'s, and both stay'
        => ['<ul><li><p>one</p><p>two</p></li></ul>', '<ul><li><p>one</p><p>two</p></li></ul>'],
    // Changed deliberately: this case used to expect the wrapper to survive beside a
    // sublist, which left an item stored in two different shapes depending on whether it
    // had one. A sublist after the text is the author's structure; the paragraph around
    // the text is still the editor's packaging.
    'a paragraph followed only by a sublist is still a wrapper'
        => [
            '<ul><li><p>Second</p><ul><li><p>Nested</p></li></ul></li></ul>',
            '<ul><li>Second<ul><li>Nested</li></ul></li></ul>',
        ],
    'a paragraph after a sublist is not a wrapper and stays'
        => [
            '<ul><li><p>Lead</p><ul><li>Nested</li></ul><p>Trailing</p></li></ul>',
            '<ul><li><p>Lead</p><ul><li>Nested</li></ul><p>Trailing</p></li></ul>',
        ],
    'a lone paragraph in a quote is unwrapped'
        => ['<blockquote><p>Quote</p></blockquote>', '<blockquote>Quote</blockquote>'],
    'two paragraphs in a quote are the author\'s, and both stay'
        => [
            '<blockquote><p>Quote</p><p>Attribution</p></blockquote>',
            '<blockquote><p>Quote</p><p>Attribution</p></blockquote>',
        ],
    // Changed deliberately: a quote now follows the same rule as a list item. This case
    // used to expect the wrapper to survive beside a list, which left quotes and items
    // storing the same shape two different ways — wording, not intent.
    'a paragraph in a quote followed only by a list is still a wrapper'
        => [
            '<blockquote><p>Quote</p><ul><li>point</li></ul></blockquote>',
            '<blockquote>Quote<ul><li>point</li></ul></blockquote>',
        ],
    'a paragraph after a list in a quote is not a wrapper and stays'
        => [
            '<blockquote><ul><li>point</li></ul><p>After</p></blockquote>',
            '<blockquote><ul><li>point</li></ul><p>After</p></blockquote>',
        ],
    'marks inside an unwrapped paragraph survive'
        => ['<ol><li><p><strong>bold</strong> item</p></li></ol>', '<ol><li><strong>bold</strong> item</li></ol>'],
    'an ordinary item is untouched'
        => ['<ul><li>plain</li></ul>', '<ul><li>plain</li></ul>'],
];
foreach ($listItems as $name => [$input, $expected]) {
    test("richtext list items: {$name}", function () use ($input, $expected) {
        assertEquals($expected, RichText::sanitize($input), 'sanitized');
        assertEquals($expected, RichText::sanitize($expected), 'not idempotent');
    });
}

// The round trip that matters for D-017: what the editor posts for a list it was given
// comes back as exactly what was stored. The right-hand side was captured in a browser.
test('richtext round trip: a list goes through the editor and is stored unchanged', function () {
    $stored = '<ul><li>one</li><li>two</li></ul>';
    $fromEditor = '<ul><li><p>one</p></li><li><p>two</p></li></ul>';

    assertEquals($stored, RichText::sanitize($fromEditor), 'the stored shape changed');
    assertEquals($stored, RichText::sanitize($stored), 'not idempotent');
});

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
// This proves the sanitiser does not change its mind. It says nothing about what the
// editor returns when given already-clean content: that needs a browser, and is checked
// there.
test('sanitising is idempotent, so an untouched save rewrites nothing', function () use ($fromEditor, $structure, $attachments, $listItems) {
    $inputs = array_merge(
        array_column(array_values($fromEditor), 0),
        array_column(array_values($structure), 0),
        array_column(array_values($attachments), 0),
        array_column(array_values($listItems), 0),
        [
            // The non-breaking space an editor emits for a trailing space. Asserting its exact
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

// PLAN.md D-014: rich text survives being opened and saved.
//
// Every string here was captured from a browser, not invented. An editor handed
// <p>alpha</p> posted <div><br>alpha<br><br></div>; before the fix each open-and-save
// added another break at each end, for ever, in every field on any page merely opened.
$roundTrip = [
    'a paragraph as the editor hands it back'
        => ['<div><br>alpha<br><br></div>', '<p>alpha</p>'],
    'one a save had already grown'
        => ['<div><br><br>alpha<br><br><br></div>', '<p>alpha</p>'],
    'one grown three times over'
        => ['<div><br><br><br>alpha<br><br><br><br></div>', '<p>alpha</p>'],
    'breaks at the edges of a heading'
        => ['<h1><br>Heading<br></h1>', '<h2>Heading</h2>'],
    'breaks at the edges of a quote'
        => ['<blockquote><br>Quote<br><br></blockquote>', '<blockquote>Quote</blockquote>'],
    // The third level, added with D-016. The editor emits it directly, so it arrives as h4
    // rather than needing a rename on the way in.
    'breaks at the edges of a third-level heading'
        => ['<h4><br>Sub<br><br></h4>', '<h4>Sub</h4>'],
    'a third-level heading passes through untouched'
        => ['<h4>Sub</h4>', '<h4>Sub</h4>'],
    'breaks at the edges of a list item'
        => ['<ul><li><br>one<br></li><li>two<br></li></ul>', '<ul><li>one</li><li>two</li></ul>'],
    // The rule takes the edges and nothing else: a break between two lines is the author's.
    'a break in the middle of a paragraph is the author\'s and stays'
        => ['<div>one<br>two</div>', '<p>one<br>two</p>'],
    'a paragraph of nothing but a break'
        => ['<div><br></div>', '<p></p>'],
];
foreach ($roundTrip as $name => [$input, $expected]) {
    test("richtext round trip: {$name}", function () use ($input, $expected) {
        assertEquals($expected, RichText::sanitize($input), 'normalised');
        assertEquals($expected, RichText::sanitize($expected), 'not idempotent');
    });
}

// The limit of the server rule, pinned so nobody concludes it is the whole fix.
//
// It trims a block's own edges, so a break trapped inside a <strong>, <em> or <a> sitting
// at the edge survives. Measured: with only this rule, those cases went on growing one
// break per open-and-save. What stopped them was giving the editor the block shapes it
// owns — in richtext.js, so they never arise.
test('richtext round trip: the server rule reaches block edges, not inside inline marks', function () {
    assertEquals(
        '<p><strong>bold</strong></p>',
        RichText::sanitize('<div><strong>bold</strong><br></div>'),
        'a break outside the mark is at the block edge and goes',
    );
    assertEquals(
        '<p><strong>bold<br></strong></p>',
        RichText::sanitize('<div><strong>bold<br></strong></div>'),
        'a break inside the mark is not at the block edge and stays',
    );
});

// Guards against regression by deletion. Not behaviour coverage.
//
// The defect these stand over is a rich text editor writing into another block's field
// after a move, a drag or a duplicate. That is browser behaviour and this runner has no
// browser, so nothing below proves the editors are bound correctly — only that the fix
// has not been removed or written back the old way. The browser check is the real one.

test('guard (source, not behaviour): the editor binds to ids that renumbering cannot reach', function () {
    $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/richtext.js');

    // The hidden input is named from a counter that belongs to the editor, so moving the
    // block cannot change which field the editor writes into. Replaces an earlier guard,
    // which also pinned a toolbar id: the toolbar is markup now, not built here.
    assertContains("'richtext-' + seq++", $js, 'the per-editor id counter is gone');
    assertContains("hidden.id = uid + '-value'", $js, 'the input id is not built from the counter');

    // Never from the textarea's id, which carries the block's position.
    assertTrue(!str_contains($js, "textarea.id + '-value'"), 'the input id encodes the block position again');
});

// The editor is TipTap (PLAN.md D-017). These stand where the previous editor's guards
// did: the schema is the storage whitelist, so the editor cannot offer what the server
// would throw away, and nothing it emits needs the sanitiser to clean up after it.
test('guard (source, not behaviour): the editor is configured to the storage whitelist', function () {
    $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/richtext.js');

    foreach (['code: false', 'codeBlock: false', 'strike: false', 'underline: false', 'horizontalRule: false'] as $off) {
        assertContains($off, $js, "a node outside the whitelist is no longer switched off: {$off}");
    }
    assertContains('levels: [2, 3, 4]', $js, 'the heading levels are not pinned to the stored set');
    // Measured: without this TipTap appends an empty paragraph to any content that does
    // not end in one, so a field changed on its first save.
    assertContains('trailingNode: false', $js, 'the trailing paragraph is back');
    // Measured: TipTap adds target and rel, which the whitelist does not allow.
    assertContains('HTMLAttributes: { target: null, rel: null }', $js, 'the link emits attributes the whitelist forbids');
});

test('guard (source, not behaviour): renumbering knows nothing about the rich text editor', function () {
    foreach (['builder.js', 'admin.js'] as $file) {
        $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/' . $file);

        assertContains("'block-' + index + '-'", $js, "{$file}: the id renumbering is gone");
        // If an id the editor binds to encodes the position again, this file has to learn
        // about the editor to keep the binding intact — the shape of the bug, not the fix.
        // Named for both, so the guard survives the editor changing under it.
        foreach (['trix', 'tiptap', 'prosemirror'] as $editor) {
            assertTrue(stripos($js, $editor) === false, "{$file} reaches for {$editor}");
        }
    }
});

test('guard (source, not behaviour): a duplicate is reset to a plain textarea before it is placed', function () {
    $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/builder-blocks.js');

    $reset = strpos($js, 'unsetRichText(groupCopy)');
    $placed = strpos($js, 'place(index + 1');
    assertTrue($reset !== false, 'the duplicate no longer resets its rich text');
    assertTrue($placed !== false && $reset < $placed, 'the copy is placed before its rich text is reset');

    // In rich mode what is on screen lives in the hidden input, while the textarea still
    // holds what the server rendered. A reset that ignored that would copy the old text
    // and quietly discard the author's edits.
    assertContains('textarea.value = hidden.value', $js, 'the duplicate takes the rendered value, not the edited one');
    assertContains('textarea.name = hidden.name', $js, 'the duplicate does not carry the field name back');
});

test('guard (source, not behaviour): the editor still renders the shapes richtext.js binds to', function () {
    $body = dispatch('/admin/pages/' . builderPage())->body;

    assertContains('data-richtext', $body, 'the wrapper richtext.js looks for');
    assertContains('data-richtext-toolbar', $body, 'the toolbar it binds by data-rt');
    assertContains('data-richtext-link', $body, 'the link row it opens');
    assertContains('data-rt="h3"', $body, 'a heading level button');
    assertContains('data-rt="undo"', $body, 'the history buttons');
    assertContains('data-richtext-source', $body, 'the textarea it upgrades');
    // The textarea keeps its position-shaped id: label[for] follows it, and renumbering
    // it is correct. Only the ids the editor binds to had to stop encoding position.
    assertTrue((bool) preg_match('~<label for="block-\d+-body">~', $body), 'the label no longer points at the field');
    assertTrue((bool) preg_match('~<textarea id="block-\d+-body"~', $body), 'the textarea id changed shape');
});
