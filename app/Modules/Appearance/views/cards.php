<?php

use App\Modules\Design\Palette;

/**
 * How a design is shown as a card: on the rail, for a character or a kept design, and in the
 * panel that asks what to do with an imported one (PLAN.md D-152). Split from appearance.php,
 * which had reached the 500-line limit; required by it, in its scope, after $swatch.
 *
 * @var Closure(string, string): string $swatch
 */
/**
 * A character or a saved design in one line, BUILT FROM ITS OWN DECISIONS: "modern · 56rem ·
 * normal · full bleed". Not a sentence somebody wrote about it — a sentence that cannot go
 * out of date.
 *
 * @param array<string, string> $decisions
 */
$summary = static fn (array $decisions, string $header): string => implode(' · ', array_filter([
    t('design.typography.' . $decisions['typography']),
    $decisions['container'] . 'rem',
    t('design.spacing.' . $decisions['spacing']),
    t($decisions['boxed'] === 'yes' ? 'appearance.boxed' : 'appearance.full_bleed'),
    // And what header it gives (D-111): a card that said nothing about the chrome was a
    // card about half the design.
    $header === '' ? '' : t('chrome.look.header_arrangement.' . $header),
]));

/**
 * One card in the left rail: three swatches, a name, what it is, and what it does.
 *
 * @param array<string, string> $decisions
 * @param string $header the header arrangement the card's design gives, '' when unknown
 * @param string $tag a word before the summary: "Custom" for a character the owner added
 */
$card = static function (array $decisions, string $header, string $name, string $badge, string $key, string $inside, string $tag = '') use ($swatch, $summary): string {
    $palette = Palette::colors($decisions['seed'], $decisions['secondary'], $decisions['surface_contrast']);

    return '<div class="rail-card' . ($badge !== '' ? ' rail-card-current' : '') . '">'
        . '<div class="rail-card-top">'
        . '<span class="rail-chips" aria-hidden="true">'
        . $swatch($palette['accent'], $key . '-accent')
        . $swatch($palette['contrast'], $key . '-contrast')
        . $swatch($palette['surface'], $key . '-surface')
        . '</span>'
        // The whole name on hover, where a long one is cut with an ellipsis.
        . '<span class="rail-card-name" title="' . e($name) . '">' . e($name) . '</span>'
        . $inside
        . '</div>'
        // The badge is the WORD, not a glyph: "in use" is a fact about the site, and a dot
        // that means it is a dot somebody has to be taught. With where a character came from,
        // when that is the owner (D-152), it starts the second line: the first keeps its room
        // for the name, which an export button beside "in use" had cut to "B…".
        . '<p class="rail-card-shape">'
        . ($badge !== '' ? '<span class="rail-live">' . e($badge) . '</span> ' : '')
        . ($tag !== '' ? '<span class="rail-custom">' . e($tag) . '</span> ' : '')
        . e($summary($decisions, $header)) . '</p>'
        . '</div>';
};