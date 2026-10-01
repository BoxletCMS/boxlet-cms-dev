<?php

/**
 * HOW A HEADER OR A FOOTER IS ARRANGED, DRAWN (PLAN.md D-161, the mockup's tiles): a strip,
 * a mark for the logo, lines for the menu and the words, placed as chrome-header.css and
 * chrome.css place them. Required by parts/controls.php, in its scope.
 *
 * SVG built from attributes only — the admin's CSP refuses a style attribute — and drawn in
 * the admin's colours by class (admin-controls.css): this is a picture of an arrangement,
 * not of the site's design.
 */

/**
 * The drawing for one answer, or '' for a choice that has none.
 */
$pictogram = static function (string $choice, string $value): string {
    // Each part as x, y, width, height on a 48×24 strip.
    $parts = [
        'header_arrangement' => [
            // The name left, the menu and the button right: the flex row.
            'left' => ['logo' => [[4, 9, 8, 6]], 'line' => [[24, 11, 14, 2], [40, 10, 5, 4]]],
            // The menu beside the name, the button at the far end.
            'inline' => ['logo' => [[4, 9, 8, 6]], 'line' => [[15, 11, 14, 2], [39, 10, 5, 4]]],
            // The name above, the menu centred under it.
            'centred' => ['logo' => [[19, 4, 10, 6]], 'line' => [[13, 15, 22, 2]]],
            // The name in the middle of its menu.
            'split' => ['logo' => [[20, 9, 8, 6]], 'line' => [[5, 11, 12, 2], [31, 11, 12, 2]]],
            // A newspaper's front: the name in a row of its own, the menu under it, left.
            'masthead' => ['logo' => [[4, 4, 12, 7]], 'line' => [[4, 15, 24, 2]]],
        ],
        'footer_layout' => [
            // One column: the words, the menu, the small print.
            'simple' => ['line' => [[4, 5, 20, 2], [4, 10, 14, 2], [4, 18, 10, 2]]],
            // Everything centred.
            'centred' => ['line' => [[14, 5, 20, 2], [17, 10, 14, 2], [19, 18, 10, 2]]],
            // The words beside the menu.
            'columns' => ['line' => [[4, 5, 16, 2], [4, 9, 12, 2], [28, 5, 10, 2], [28, 9, 8, 2], [28, 13, 9, 2], [4, 19, 40, 1]]],
            // The menu in a row above the words.
            'menu_first' => ['line' => [[4, 5, 7, 2], [13, 5, 7, 2], [22, 5, 7, 2], [4, 11, 22, 2], [4, 18, 10, 2]]],
            // Three columns: the words, the menu, the languages and the small print.
            'three' => ['line' => [[4, 5, 11, 2], [4, 9, 8, 2], [19, 5, 10, 2], [19, 9, 8, 2], [33, 5, 11, 2], [33, 9, 7, 2]]],
        ],
    ][$choice][$value] ?? null;
    if ($parts === null) {
        return '';
    }
    $svg = '<svg viewBox="0 0 48 24" focusable="false"><rect class="pict-ground" width="48" height="24" rx="3"/>';
    foreach ($parts as $class => $rects) {
        foreach ($rects as [$x, $y, $width, $height]) {
            $svg .= '<rect class="pict-' . $class . '" x="' . $x . '" y="' . $y . '" width="' . $width . '" height="' . $height . '" rx="1"/>';
        }
    }

    return $svg . '</svg>';
};
