<?php

use App\Modules\Design\Color;

// The admin's own contrast rule, measured rather than eyeballed (PLAN.md D-012).
//
// 4.5:1 for text. 3:1 for the boundary of a control that is empty at rest — a text field,
// the colour swatch, the editor box — which has no text of its own to be found by, so its
// border is the only thing saying it is there. SPEC §5.4 records that the admin has a
// fixed --ui-* token set of its own but states no numbers, so they live here, where they
// are enforced.
//
// This asserts the PALETTE, not the rules. A parser pairing each rule's own colour with
// its own background reports false failures: admin-ui.css restates the ink of a button
// whose background is set by a different rule, and white-on-white would be the verdict.
// Scoping selectors well enough to avoid that is a CSS engine. So instead: every ink
// clears 4.5:1 against every surface it could meet, and every colour in the admin comes
// from the palette. Between them those cover every pair the stylesheets can produce.
//
// A hand-kept list of pairs would not, and that is not hypothetical: admin-richtext.css
// was missing from adminStylesheets() long enough for a toolbar icon to reach 1.12:1.

/** Ink tokens: anything the admin puts text or an icon in. */
const ADMIN_INK = ['ink', 'ink-muted', 'ink-faint', 'accent', 'accent-dark', 'danger', 'success', 'warning'];

/** Surfaces: anything the admin paints behind that ink. */
const ADMIN_SURFACE = [
    'bg', 'panel', 'panel-sunken',
    'accent-soft', 'danger-soft', 'success-soft', 'warning-soft',
];

/**
 * The admin's colour tokens, without the `--ui-` prefix.
 *
 * @return array<string, string> name => #rrggbb
 */
function adminTokens(): array
{
    $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/admin.css');
    preg_match_all('~--ui-([a-z-]+)\s*:\s*(#[0-9a-f]{6})\s*;~', $css, $matches, PREG_SET_ORDER);

    $tokens = [];
    foreach ($matches as $match) {
        $tokens[$match[1]] = $match[2];
    }

    return $tokens;
}

/**
 * Every rule in a stylesheet, as selector => declaration text. Not a parser: it splits on
 * braces, which is enough because the admin has no nested rules and no @media block that
 * wraps one. Comments go first, so prose inside them cannot look like a declaration.
 *
 * @return list<array{string, string}>
 */
function cssRules(string $file): array
{
    $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/' . $file);
    $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);

    $rules = [];
    foreach (explode('}', $css) as $chunk) {
        if (!str_contains($chunk, '{')) {
            continue;
        }
        [$selector, $body] = explode('{', $chunk, 2);
        $rules[] = [trim((string) preg_replace('~\s+~', ' ', $selector)), $body];
    }

    return $rules;
}

test('every admin ink is readable on every admin surface', function () {
    $tokens = adminTokens();

    foreach (ADMIN_INK as $ink) {
        assertTrue(isset($tokens[$ink]), "--ui-{$ink} is gone from admin.css");
        foreach (ADMIN_SURFACE as $surface) {
            assertTrue(isset($tokens[$surface]), "--ui-{$surface} is gone from admin.css");
            $ratio = Color::contrast($tokens[$ink], $tokens[$surface]);
            assertTrue($ratio >= 4.5, sprintf(
                '--ui-%s (%s) on --ui-%s (%s) is %.2f:1, under the 4.5:1 text rule',
                $ink,
                $tokens[$ink],
                $surface,
                $tokens[$surface],
                $ratio,
            ));
        }
    }
});

test('the ink on a filled accent surface is readable', function () {
    $tokens = adminTokens();

    // --ui-on-accent is the one ink that never meets a pale surface: it exists for the
    // primary button and its hover, which are the accent colours themselves.
    foreach (['accent', 'accent-dark'] as $surface) {
        $ratio = Color::contrast($tokens['on-accent'], $tokens[$surface]);
        assertTrue($ratio >= 4.5, sprintf(
            '--ui-on-accent (%s) on --ui-%s (%s) is %.2f:1, under the 4.5:1 text rule',
            $tokens['on-accent'],
            $surface,
            $tokens[$surface],
            $ratio,
        ));
    }
});

test('a control that is empty at rest has an edge you can see', function () {
    $tokens = adminTokens();

    assertTrue(isset($tokens['control-line']), '--ui-control-line is gone: controls draw their edge with what now?');
    foreach (ADMIN_SURFACE as $surface) {
        $ratio = Color::contrast($tokens['control-line'], $tokens[$surface]);
        assertTrue($ratio >= 3.0, sprintf(
            '--ui-control-line (%s) on --ui-%s (%s) is %.2f:1, under the 3:1 control rule',
            $tokens['control-line'],
            $surface,
            $tokens[$surface],
            $ratio,
        ));
    }

    // The distinction the token exists for. --ui-line-strong stays light on purpose: it
    // draws containers and badges, which are not controls and are not being asserted here.
    // If a control ever borrows it again, it inherits 1.55:1 against the panel.
    foreach (adminStylesheets() as $file) {
        foreach (cssRules($file) as [$selector, $body]) {
            if (!str_contains($body, '--ui-line-strong')) {
                continue;
            }
            foreach (explode(',', $selector) as $part) {
                // The last compound is what the rule actually styles. Matching the whole
                // selector called `.richtext-editor blockquote` a control, when it is a
                // quote inside the editor: authored content, whose left rule is meant to
                // be quiet.
                $subject = (string) preg_replace('#^.*[\s>+~]#', '', trim($part));
                assertTrue(
                    !preg_match('~input|textarea|select|richtext-editor~', $subject),
                    "{$file}: {$selector} draws a control with --ui-line-strong, not --ui-control-line",
                );
            }
        }
    }
});

test('every colour in the admin comes from the admin palette', function () {
    $tokens = adminTokens();
    $allowed = ['transparent', 'inherit', 'currentcolor', 'none', 'unset'];

    foreach (adminStylesheets() as $file) {
        // canvas.css is the declared exception, and the reason is in its own header: it
        // loads into a document full of the SITE's tokens, so what sits behind its controls
        // is the user's design, not a surface this palette knows. Its colours are literals
        // on purpose and their legibility cannot be computed from the admin's tokens —
        // .bx-insert carries two tones so that one edge contrasts whatever is behind it.
        if ($file === 'canvas.css') {
            continue;
        }

        foreach (cssRules($file) as [$selector, $body]) {
            preg_match_all('~(?:^|;)\s*(background|background-color|color|border-color|outline-color)\s*:([^;]+)~i', $body, $found, PREG_SET_ORDER);
            foreach ($found as $declaration) {
                $value = strtolower(trim($declaration[2]));
                if (in_array($value, $allowed, true) || str_starts_with($value, 'var(--ui-')) {
                    continue;
                }
                fail("{$file}: {$selector} sets {$declaration[1]}: {$value}, which is not an --ui- token");
            }
        }
    }
});

// canvas.css is the one admin stylesheet the palette test skips, because what sits behind
// its controls is the user's design rather than a surface this palette knows. That makes
// this guard the only thing standing over the insertion control, so it is written to the
// property that actually keeps it legible: two tones, in every state.
//
// Contrast depends only on relative luminance, so sweeping luminance covers every colour a
// page can be. With an ink edge and a white edge, the better of the two never drops below
// 4.16:1 anywhere in that range. With only one of them it does: filling the disc with the
// accent on hover, and leaving the white ring alone to carry it, measured 2.39:1 — hover
// making the control harder to see than at rest.
test('the insertion control keeps two tones in every state', function () {
    $rules = [];
    foreach (cssRules('canvas.css') as [$selector, $body]) {
        $rules[$selector] = $body;
    }

    assertTrue(isset($rules['.bx-insert']), '.bx-insert is gone from canvas.css');
    $rest = $rules['.bx-insert'];
    assertContains('background: var(--bx-ink)', $rest, 'the resting disc is no longer the dark tone');
    assertTrue(
        (bool) preg_match('~border:[^;]*#ffffff~', $rest),
        'the resting control lost its white ring: on a dark page nothing else marks its edge',
    );

    $hover = $rules['.bx-insert:hover, .bx-insert:focus-visible'] ?? '';
    assertTrue($hover !== '', 'the hover and focus rule changed shape; check both still carry two tones');
    assertContains('--bx-ink', $hover, 'hover fills with the accent and keeps no dark edge, which measured 2.39:1');
});

test('opacity is never what makes a control quiet', function () {
    // These states are allowed to fade, and every one is transient: something is being
    // dragged, or is waiting for a fetch. None is a control at rest, which is the case the
    // rule is about — .bx-insert sat at 0.35 and measured 1.69:1 until D-012 (PLAN.md).
    //
    // .media-picker-results joins for exactly the reason .library-card already had: it
    // fades only while media-picker.js is fetching the listing, between setting aria-busy
    // and clearing it. The attribute tells assistive technology; the fade tells everyone
    // else that the wait is the program working rather than an empty panel.
    $transient = [
        '.bx-canvas .bx-dragging',
        '.is-dragging',
        '.library-card[aria-busy="true"]',
        '.media-picker-results[aria-busy="true"]',
    ];

    foreach (adminStylesheets() as $file) {
        foreach (cssRules($file) as [$selector, $body]) {
            if (!preg_match('~(?:^|;)\s*opacity\s*:\s*([0-9.]+)~', $body, $match)) {
                continue;
            }
            if ((float) $match[1] >= 1.0) {
                continue;
            }
            assertTrue(in_array($selector, $transient, true), sprintf(
                '%s: %s fades to %s. A control at rest must be legible without hover; if this is a '
                . 'transient state, add it to the list in this test and say why.',
                $file,
                $selector,
                $match[1],
            ));
        }
    }
});
