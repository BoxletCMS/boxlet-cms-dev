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
 * Every palette block in admin-tokens.css, as the declarations it holds (D-054).
 *
 * Keyed by the theme in its selector: '' for the plain `.admin` default, then 'light' and
 * 'system'. Comments go first, so the selectors listed in the file's own header cannot look
 * like blocks; a block ends at a closing brace indented exactly as its selector was, which
 * is what lets the one inside the @media be found without a CSS parser.
 *
 * @return array<string, string> theme => the declarations between its braces
 */
function adminThemeBlocks(): array
{
    $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/admin-tokens.css');
    $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
    preg_match_all('~^([ ]*)\.admin(?:\[data-ui-theme="([a-z]+)"\])?\s*\{(.*?)^\1\}~ms', $css, $matches, PREG_SET_ORDER);

    $blocks = [];
    foreach ($matches as $match) {
        $blocks[$match[2]] = $match[3];
    }

    return $blocks;
}

/**
 * One palette's colour tokens, without the `--ui-` prefix.
 *
 * A theme other than the default is a block of OVERRIDES, so it is read on top of the
 * default: a token it does not restate keeps the value it has there, exactly as the
 * cascade gives it to the browser.
 *
 * @return array<string, string> name => #rrggbb
 */
function adminTokens(string $theme = ''): array
{
    $blocks = adminThemeBlocks();
    $read = static function (string $body): array {
        preg_match_all('~--ui-([a-z-]+)\s*:\s*(#[0-9a-f]{6})\s*;~', $body, $matches, PREG_SET_ORDER);
        $tokens = [];
        foreach ($matches as $match) {
            $tokens[$match[1]] = $match[2];
        }

        return $tokens;
    };

    return $read($blocks[$theme] ?? '') + $read($blocks[''] ?? '');
}

/** The palettes every colour test runs twice over: the dark default, and warm paper. */
const ADMIN_THEMES = ['', 'light'];

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
    foreach (ADMIN_THEMES as $theme) {
        $tokens = adminTokens($theme);
        $where = $theme === '' ? 'the default palette' : "the {$theme} palette";

        foreach (ADMIN_INK as $ink) {
            assertTrue(isset($tokens[$ink]), "--ui-{$ink} is gone from {$where}");
            foreach (ADMIN_SURFACE as $surface) {
                assertTrue(isset($tokens[$surface]), "--ui-{$surface} is gone from {$where}");
                $ratio = Color::contrast($tokens[$ink], $tokens[$surface]);
                assertTrue($ratio >= 4.5, sprintf(
                    '%s: --ui-%s (%s) on --ui-%s (%s) is %.2f:1, under the 4.5:1 text rule',
                    $where,
                    $ink,
                    $tokens[$ink],
                    $surface,
                    $tokens[$surface],
                    $ratio,
                ));
            }
        }
    }
});

// The two palettes are one admin. A token declared in one and not the other is a screen
// that falls back to the dark value on paper — which is how a theme rots silently.
test('both palettes declare the same colour tokens', function () {
    $blocks = adminThemeBlocks();
    assertTrue(isset($blocks[''], $blocks['light'], $blocks['system']), 'a palette block is missing from admin-tokens.css');

    $names = static function (string $body): array {
        preg_match_all('~--ui-([a-z-]+)\s*:~', $body, $found);
        $list = $found[1];
        sort($list);

        return $list;
    };
    assertEquals($names($blocks['']), $names($blocks['light']), 'the light palette and the default do not declare the same tokens');
});

// The light values are written out twice — once for the person who chose light, once for
// the person who chose "match the system" on a light machine — because plain CSS cannot
// give one block two selectors across a media query and Boxlet has no build step. The
// duplication is honest only while the two copies are the same.
test('the two light palettes are the same palette', function () {
    $blocks = adminThemeBlocks();
    $lines = static function (string $body): array {
        $out = [];
        foreach (explode("\n", $body) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    };

    assertEquals(
        $lines($blocks['light'] ?? ''),
        $lines($blocks['system'] ?? ''),
        'the "light" and the "match the system" blocks in admin-tokens.css have drifted apart',
    );
});

// The rail and the palette dialog are the frame, painted a step away from the page in
// whichever direction the palette runs, so their inks are measured against them alone: the
// matrix above pairs inks with the page's own surfaces. --ui-ink-faint joins them because
// the rail's counts are drawn in it, and the accent tint joins the surfaces because it is
// what marks the rail's current entry.
test('the frame\'s inks are readable on the frame', function () {
    foreach (ADMIN_THEMES as $theme) {
        $tokens = adminTokens($theme);
        $where = $theme === '' ? 'the default palette' : "the {$theme} palette";

        foreach (['bar', 'bar-ink', 'bar-ink-muted', 'bar-raised', 'current', 'mark'] as $name) {
            assertTrue(isset($tokens[$name]), "--ui-{$name} is gone from {$where}");
        }
        foreach (['bar-ink', 'bar-ink-muted'] as $ink) {
            foreach (['bar', 'bar-raised', 'accent-soft', 'current'] as $surface) {
                $ratio = Color::contrast($tokens[$ink], $tokens[$surface]);
                assertTrue($ratio >= 4.5, sprintf('%s: --ui-%s on --ui-%s is %.2f:1, under the 4.5:1 text rule', $where, $ink, $surface, $ratio));
            }
        }
        // The faint ink draws the rail's counts, so it is measured where they actually sit.
        // Not on --ui-current: that is the deepest ground in the rail, where it measured
        // 4.09:1, and the count in the current row is drawn in the muted ink instead
        // (admin-shell.css). If that rule ever goes, this list is the thing to widen.
        foreach (['bar', 'bar-raised', 'accent-soft'] as $surface) {
            $ratio = Color::contrast($tokens['ink-faint'], $tokens[$surface]);
            assertTrue($ratio >= 4.5, sprintf('%s: --ui-ink-faint on --ui-%s is %.2f:1, under the 4.5:1 text rule', $where, $surface, $ratio));
        }
        // The bar down the left of the current entry is what the accent is spent on there,
        // and it is a shape rather than words.
        $bar = Color::contrast($tokens['accent'], $tokens['current']);
        assertTrue($bar >= 3.0, sprintf('%s: --ui-accent on --ui-current is %.2f:1, under the 3:1 rule for a shape', $where, $bar));
    }
});

// The mark is decoration and carries no text, so it is held to the 3:1 of a shape rather
// than the 4.5:1 of words — but it is a shape you must be able to SEE, on the rail where
// it is the brand and on a panel where it opens the login card.
//
// Measured, against the handoff's advice that the orange never needs re-tuning: #ff6b3d
// on warm paper's rail is 2.27:1. Orange on ink and orange on paper are not the same
// colour. The light palette carries its own, deeper value.
test('the brand mark is a shape you can see', function () {
    foreach (ADMIN_THEMES as $theme) {
        $tokens = adminTokens($theme);
        $where = $theme === '' ? 'the default palette' : "the {$theme} palette";

        foreach (['bar', 'panel', 'bg'] as $surface) {
            $ratio = Color::contrast($tokens['mark'], $tokens[$surface]);
            assertTrue($ratio >= 3.0, sprintf(
                '%s: --ui-mark (%s) on --ui-%s (%s) is %.2f:1, under the 3:1 rule for a shape',
                $where,
                $tokens['mark'],
                $surface,
                $tokens[$surface],
                $ratio,
            ));
        }
    }
});

test('the ink on a filled accent surface is readable', function () {
    foreach (ADMIN_THEMES as $theme) {
        $tokens = adminTokens($theme);
        $where = $theme === '' ? 'the default palette' : "the {$theme} palette";

        // --ui-on-accent is the one ink that never meets a page surface: it exists for the
        // primary button and its hover, which are the accent colours themselves.
        foreach (['accent', 'accent-dark'] as $surface) {
            $ratio = Color::contrast($tokens['on-accent'], $tokens[$surface]);
            assertTrue($ratio >= 4.5, sprintf(
                '%s: --ui-on-accent (%s) on --ui-%s (%s) is %.2f:1, under the 4.5:1 text rule',
                $where,
                $tokens['on-accent'],
                $surface,
                $tokens[$surface],
                $ratio,
            ));
        }
    }
});

/**
 * Every button variant rule in the admin, as the colours it declares.
 *
 * Scanned from adminStylesheets(), which is derived from disk, so a variant added to a new
 * stylesheet tomorrow is measured without anyone remembering to list it here — the failure
 * mode that let a toolbar icon reach 1.12:1.
 *
 * Only `.button-*`. The base `.button` and its `.admin a.button` specificity repeat are not
 * variants: the base defines the pair, and the repeat exists to outrank an anchor's colour.
 *
 * @return array<string, array{color: string|null, background: string|null, border: string|null}>
 */
function buttonVariants(): array
{
    $variants = [];
    foreach (adminStylesheets() as $file) {
        foreach (cssRules($file) as [$selector, $body]) {
            if (preg_match('~\.button-[a-z-]+~', $selector) !== 1) {
                continue;
            }
            $variants[$selector] = [
                'color' => declaredColour($body, 'color'),
                'background' => declaredColour($body, 'background') ?? declaredColour($body, 'background-color'),
                'border' => declaredColour($body, 'border-color'),
            ];
        }
    }

    return $variants;
}

/**
 * The --ui- token a declaration sets, the word 'transparent', or null when it sets neither.
 *
 * `background` and `background-color` are matched separately on purpose: the property name
 * is followed by a colon in one and by a hyphen in the other, so one pattern cannot stand
 * for both without also matching things it should not.
 */
function declaredColour(string $body, string $property): ?string
{
    if (preg_match('~(?:^|;)\s*' . preg_quote($property, '~') . '\s*:\s*([^;]+)~', $body, $found) !== 1) {
        return null;
    }
    $value = trim($found[1]);
    if ($value === 'transparent') {
        return 'transparent';
    }

    return preg_match('~var\(--ui-([a-z-]+)\)~', $value, $token) === 1 ? $token[1] : null;
}

// The defect this stands over, measured at 1.15:1. `.button-danger` set a colour and no
// background, so on the filled `.button` it was red ink on the blue accent — and the matrix
// test above never saw it, because it pairs inks with PALE surfaces and `accent` lives in
// its ink list rather than its surface list. A variant that changes the ink must bring its
// own ground, or it inherits one nobody measured it against.
test('a button variant that changes the ink brings its own background', function () {
    foreach (buttonVariants() as $selector => $rule) {
        if ($rule['color'] === null) {
            // A state that only repaints the ground, such as :hover. It keeps the ink of the
            // rule it is a state of, which that rule has already been measured for.
            continue;
        }

        assertTrue($rule['background'] !== null, sprintf(
            '%s sets a colour but no background, so it inherits a surface it was never measured against',
            $selector,
        ));
    }
});

test('a button variant is readable on the ground it declares', function () {
    // Where a button can sit when its own background lets the page through.
    $grounds = ['bg', 'panel', 'panel-sunken'];

    foreach (ADMIN_THEMES as $theme) {
        $tokens = adminTokens($theme);
        $where = $theme === '' ? 'the default palette' : "the {$theme} palette";

        foreach (buttonVariants() as $selector => $rule) {
            if ($rule['color'] === null || $rule['background'] === null) {
                continue;
            }

            assertTrue(isset($tokens[$rule['color']]), "{$selector}: --ui-{$rule['color']} is not a token");
            $ink = $tokens[$rule['color']];

            // transparent is not a colour to measure against: the page shows through, so the
            // ink has to read on every ground a button can be placed on.
            $against = $rule['background'] === 'transparent' ? $grounds : [$rule['background']];

            foreach ($against as $name) {
                assertTrue(isset($tokens[$name]), "{$selector}: --ui-{$name} is not a token");
                $ratio = Color::contrast($ink, $tokens[$name]);
                assertTrue($ratio >= 4.5, sprintf(
                    '%s, %s: --ui-%s (%s) on --ui-%s (%s) is %.2f:1, under the 4.5:1 text rule',
                    $where,
                    $selector,
                    $rule['color'],
                    $ink,
                    $name,
                    $tokens[$name],
                    $ratio,
                ));
            }
        }
    }
});

test('a control that is empty at rest has an edge you can see', function () {
    foreach (ADMIN_THEMES as $theme) {
        $tokens = adminTokens($theme);
        $where = $theme === '' ? 'the default palette' : "the {$theme} palette";

        assertTrue(isset($tokens['control-line']), '--ui-control-line is gone: controls draw their edge with what now?');
        foreach (ADMIN_SURFACE as $surface) {
            $ratio = Color::contrast($tokens['control-line'], $tokens[$surface]);
            assertTrue($ratio >= 3.0, sprintf(
                '%s: --ui-control-line (%s) on --ui-%s (%s) is %.2f:1, under the 3:1 control rule',
                $where,
                $tokens['control-line'],
                $surface,
                $tokens[$surface],
                $ratio,
            ));
        }
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

// The owner asked for softer field borders (D-038). A field is still found by its bottom
// edge: any rule drawing a control's border in the soft line must keep the bottom in
// --ui-control-line, which the test above holds to 3:1. Without that, "softer" would
// quietly become the invisible field D-012 exists to prevent.
test('a softly drawn field keeps an edge you can see', function () {
    $tokens = adminTokens();
    assertTrue(isset($tokens['control-soft']), '--ui-control-soft is gone; is this guard still needed?');

    $found = 0;
    foreach (adminStylesheets() as $file) {
        foreach (cssRules($file) as [$selector, $body]) {
            if (!preg_match('~border(?:-color)?\s*:[^;]*--ui-control-soft~', $body)) {
                continue;
            }
            $found++;
            assertTrue(
                (bool) preg_match('~border-bottom-color\s*:\s*var\(--ui-control-line\)~', $body),
                "{$file}: {$selector} draws a control in --ui-control-soft with no --ui-control-line edge",
            );
        }
    }
    assertTrue($found > 0, 'no rule uses --ui-control-soft: the guard is looking at nothing');
});

test('every colour in the admin comes from the admin palette', function () {
    $allowed = ['transparent', 'inherit', 'currentcolor', 'none', 'unset'];

    foreach (adminStylesheets() as $file) {
        // admin-tokens.css IS the palette: it is the one file whose whole job is to say
        // what colour anything is, and the test above measures every value in it.
        if ($file === 'admin-tokens.css') {
            continue;
        }

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
                /*
                 * A VALUE BUILT OUT OF ADMIN TOKENS IS STILL THE ADMIN'S PALETTE. The world
                 * map's five steps are the accent mixed into the page behind it (O-20); the
                 * library card's fade is the panel's own surface falling to nothing (D-083).
                 *
                 * The rule was written for color-mix() alone, and the first gradient of pure
                 * tokens failed it — not because it broke the rule but because the guard had
                 * only met one shape of it. Widened deliberately, to any value, on exactly
                 * the arithmetic it already used: it must name at least one --ui- token, and
                 * every colour reference in it must be one. A literal anywhere, or a token
                 * belonging to the SITE rather than the admin, still fails as it would alone.
                 * Requiring a token is what stops the widening from letting through a value
                 * that names no colour at all, such as a bare url().
                 */
                $tokens = preg_match_all('~var\(--ui-[a-z-]+\)~', $value);
                if ($tokens > 0 && $tokens === preg_match_all('~#[0-9a-f]{3,8}|\brgba?\(|\bhsla?\(|var\(~', $value)) {
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

    /* THE CONTROL IS THE PILL INSIDE THE STRIP, since D-106. `.bx-insert` used to be the
       pill itself; it is now the full-width seam it sits on, and the two tones moved with
       the control to `.bx-insert > span`. The rule did not change and neither did the
       measurement behind it — what changed is which selector carries it, and this test is
       followed to it rather than relaxed. */
    assertTrue(isset($rules['.bx-insert > span']), '.bx-insert > span is gone from canvas.css');
    $rest = $rules['.bx-insert > span'];
    assertContains('background: var(--bx-ink)', $rest, 'the resting disc is no longer the dark tone');
    assertTrue(
        (bool) preg_match('~border:[^;]*#ffffff~', $rest),
        'the resting control lost its white ring: on a dark page nothing else marks its edge',
    );

    // And the seam it lies on has no edge of its own to be mistaken for the band's (D-106).
    assertTrue(
        !preg_match('~(?:border|outline):\s*[1-9]~', $rules['.bx-insert'] ?? ''),
        'the seam strip grew an edge again; over an outlined band two sets of dashes read as one confused thing',
    );

    $hover = $rules['.bx-insert:hover > span, .bx-insert:focus-visible > span'] ?? '';
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
