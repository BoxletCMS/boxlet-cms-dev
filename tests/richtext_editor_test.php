<?php

// Guards against regression by deletion. Not behaviour coverage.
//
// Split out of richtext_test.php, which had grown past the file limit. Nothing here changed
// in the split. These are about the editor in the browser rather than the sanitiser, which
// is why they sit apart from the whitelist and the block shapes.
//
// The defect they stand over is a rich text editor writing into another block's field after
// a move, a drag or a duplicate. That is browser behaviour and this runner has no browser,
// so nothing below proves the editors are bound correctly — only that the fix has not been
// removed or written back the old way. The browser check is the real one.

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

// The link panel is hidden by the hidden attribute, and admin.css makes that attribute win
// over any rule that would lay the element out. It was permanently on screen until it did:
// .richtext-link { display: flex } is an author rule and the browser's own
// [hidden] { display: none } is not.
test('guard (source, not behaviour): the admin makes the hidden attribute win', function () {
    $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/admin.css');

    assertTrue(
        (bool) preg_match('~\.admin \[hidden\]\s*\{\s*display:\s*none~', $css),
        'the rule that hides anything carrying the hidden attribute is gone',
    );

    // Declarations only. Asserting over the raw file failed on the comment above that rule,
    // which says "Specificity, not !important" — the guard tripping over the sentence
    // explaining the guard. A declaration ends in a semicolon or closes the block; prose
    // does not.
    $declarations = preg_replace('~/\*.*?\*/~s', '', $css) ?? $css;
    assertTrue(
        !preg_match('~!important\s*[;}]~', $declarations),
        'the admin wins with !important somewhere instead of on specificity',
    );
});

// Ctrl+Shift+V belongs to the browser, not to us (2c-3).
//
// Measured with a real clipboard — rich markup copied out of a contenteditable with Ctrl+C,
// then pasted twice: Ctrl+V put <strong>, <em> and <a href> into the field, and
// Ctrl+Shift+V put "<p>Bold and italic with a link</p>", with no marks at all. Chrome and
// ProseMirror do it between them, so a handler here would be a second implementation of
// something already correct, and the one thing worth pinning is that nobody adds one.
test('guard (source, not behaviour): nothing duplicates the browser\'s plain-text paste', function () {
    $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/richtext.js');

    assertTrue(!preg_match("~addEventListener\(\s*'paste'~", $js), 'richtext.js handles paste itself again');
    assertTrue(stripos($js, 'clipboardData') === false, 'richtext.js reads the clipboard itself again');

    // The field promises the chord works. If the promise and the behaviour ever part
    // company, it should be because someone changed this line on purpose.
    $lang = require dirname(__DIR__) . '/lang/en.php';
    assertContains('Ctrl+Shift+V', (string) ($lang['richtext.paste_plain'] ?? ''), 'the hint no longer names the chord');
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
