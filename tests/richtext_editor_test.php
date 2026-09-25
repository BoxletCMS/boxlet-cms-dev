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

// The canvas follows the text as it is typed by listening for `input` on the field groups
// (builder-blocks.js debounces redraw). Assigning .value in script fires nothing, so the
// editor has to say so itself — without this line a rich text edit was invisible both to
// the canvas and to the unsaved-changes warning, while every other field type worked,
// because a person typing into a real control fires its own event.
//
// A guard rather than behaviour coverage: this runner has no browser. It stands over a
// deletion, which is how this would come back — the bug was the absence of an event, and
// nothing on screen said so.
test('guard (source, not behaviour): a rich text edit announces itself', function () {
    $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/richtext.js');

    assertTrue(
        (bool) preg_match('~onUpdate[^}]*hidden\.value\s*=\s*editor\.getHTML\(\)~s', $js),
        'the editor no longer writes what it will post into the hidden input',
    );
    assertTrue(
        (bool) preg_match("~hidden\.dispatchEvent\(\s*new Event\(\s*'input',\s*\{\s*bubbles:\s*true~", $js),
        'the editor stopped announcing its edits, so the canvas will not follow the text',
    );

    // The listener the event has to reach, and the delay it is debounced by — in
    // builder-redraw.js since the split of D-117.
    $blocks = (string) file_get_contents(dirname(__DIR__) . '/public/assets/builder-redraw.js');
    assertContains("api.groups.addEventListener('input'", $blocks, 'the field groups no longer listen for input');
    assertTrue(
        (bool) preg_match('~setTimeout\(redraw,\s*(\d{2,4})\)~', $blocks, $delay) && (int) $delay[1] <= 400,
        'the redraw debounce is longer than 400ms, which reads as lag rather than as the page following you',
    );
});

test('guard (source, not behaviour): naming a group knows nothing about the rich text editor', function () {
    // Ids used to be rewritten on every add, move and remove, and this guard existed so
    // that rewriting could never need to know about the editor bound to them. Since D-094 a
    // group is named ONCE, when it is born, by admin.js — so the thing to guard is that
    // one function, and that builder.js has stopped doing it at all.
    // The signature gained a section key at D-098, which is the guard doing its job rather
    // than failing: what it protects is that ONE function names a group, not that its
    // parameters never change. Matched loosely enough to survive a fourth argument and
    // strictly enough to notice the function going away.
    assertTrue(
        (bool) preg_match('~function nameGroup\(group, key\b~', (string) file_get_contents(dirname(__DIR__) . '/public/assets/admin.js')),
        'the one place a field group is named has gone or been renamed',
    );
    assertTrue(
        !str_contains((string) file_get_contents(dirname(__DIR__) . '/public/assets/builder.js'), "element.name = element.name.replace"),
        'builder.js rewrites names again, which is what stable keys removed',
    );

    foreach (['builder.js', 'admin.js'] as $file) {
        $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/' . $file);

        // If an id the editor binds to encodes the position again, this file has to learn
        // about the editor to keep the binding intact — the shape of the bug, not the fix.
        // Named for both, so the guard survives the editor changing under it.
        foreach (['trix', 'tiptap', 'prosemirror'] as $editor) {
            assertTrue(stripos($js, $editor) === false, "{$file} reaches for {$editor}");
        }
    }
});

test('guard (source, not behaviour): a duplicate is reset to a plain textarea before it is placed', function () {
    // builder-actions.js since the split of D-117.
    $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/builder-actions.js');

    /* A duplicate no longer becomes a band of its own at the next position on the page
       (D-103) — it stands beside the block it was copied from, in the same column — so what
       places it is `group.after(groupCopy)` and not place(). The rule is unchanged and is
       what this guards: the copy's rich text is dead markup until it is reset, and resetting
       it after it is on the screen is a window in which the author can type into nothing. */
    $reset = strpos($js, 'unsetRichText(groupCopy)');
    $placed = strpos($js, 'group.after(groupCopy)');
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
    // Asked of t(), not of a file: en.php was split by concern, and a test naming one
    // file fails whenever a string moves between them, which says nothing about the
    // editor. t() returns the key itself when nothing is defined, so a missing string
    // fails this assertion rather than passing an empty one.
    assertContains('Ctrl+Shift+V', t('richtext.paste_plain'), 'the hint no longer names the chord');
});

test('guard (source, not behaviour): the editor still renders the shapes richtext.js binds to', function () {
    $body = dispatch('/admin/pages/' . builderPage())->body;

    assertContains('data-richtext', $body, 'the wrapper richtext.js looks for');
    assertContains('data-richtext-toolbar', $body, 'the toolbar it binds by data-rt');
    assertContains('data-richtext-link', $body, 'the link row it opens');
    assertContains('data-rt="h3"', $body, 'a heading level button');
    assertContains('data-rt="undo"', $body, 'the history buttons');
    assertContains('data-richtext-source', $body, 'the textarea it upgrades');
    /*
     * The label has to point at the textarea. Asserted as that RELATION rather than as the
     * shape of an id: the shape changed with D-094, from block-3-body to block-b42-body,
     * and a guard written against the shape reported "the label no longer points at the
     * field" when the label pointed at the field perfectly well.
     */
    if (preg_match('~<label for="(block-[a-z0-9]+-body)">~', $body, $labelled) !== 1) {
        fail('no label for a body field');
    }
    assertContains('<textarea id="' . $labelled[1] . '"', $body, 'the label points at no textarea');
});
