<?php

use App\Modules\Appearance\LayoutDiagram;
use App\Modules\Design\Tokens;

/**
 * Layout & widths (PLAN.md D-157): ONE PLACE FOR EVERY WIDTH. The content's measure was under
 * Shape, the boxed page and its sheet under Page, how far the header and footer reach under
 * Header and Footer — five widths in four tabs, and the only way to see how they related was
 * to look at the result. Here they are together, under a drawing of all of them.
 *
 * Included by appearance.php inside its section, with the closures of parts/controls.php in
 * scope.
 *
 * @var array<string, string> $decisions
 * @var array<string, string> $readouts
 * @var Closure(string, array<array-key, string>): string $segmented
 * @var Closure(string, float, float, float, array<array-key, string>=): string $slider
 * @var Closure(string, list<string>): array<string, string> $labels
 * @var Closure(string): string $ownColour
 * @var Closure(string, string, string, bool=): string $group
 */
$marks = [];
foreach (['narrow', 'normal', 'wide'] as $name) {
    $marks[(string) Tokens::CONTAINER_NAMES[$name]] = t('design.container.' . $name);
}

ob_start();
?>
                    <?php /* Drawn by the server from the decisions, moved by the answer /check gives
                             while a control changes (LayoutDiagram). The legend is the picture's
                             accessible name. */ ?>
                    <figure class="layout-figure">
                        <?= LayoutDiagram::svg($decisions) ?>
                        <figcaption class="layout-legend" id="layout-diagram-legend">
                            <span class="legend-sheet" data-readout="layout.sheet"><?= e($readouts['layout.sheet'] ?? '') ?></span>
                            <span class="legend-content" data-readout="layout.content"><?= e($readouts['layout.content'] ?? '') ?></span>
                            <span class="legend-chrome"><?= e(t('inspector.layout.chrome')) ?></span>
                        </figcaption>
                    </figure>
<?php $diagram = (string) ob_get_clean(); ob_start(); ?>
                    <?php /* A NUMBER, NOT FOUR NAMES (D-062): the measure is the decision that most
                             changes whether a page is comfortable to read. The names stand under
                             the slider where their widths are. */ ?>
                    <?= $slider('container', Tokens::CONTAINER_MIN, Tokens::CONTAINER_MAX, Tokens::CONTAINER_STEP, $marks) ?>
<?php $content = (string) ob_get_clean(); ob_start(); ?>
                    <?= $segmented('boxed', $labels('boxed', Tokens::BOXED)) ?>
                    <?php /* EVERYTHING BELOW ONLY EXISTS ON A BOXED PAGE, AND IS ONLY SHOWN THERE
                             (D-122): an owner once set a sheet's width for a page that was not
                             boxed, saw nothing happen, and took the page to be boxed. Hidden by
                             the stylesheet on the form's own state; the fields stay in the form. */ ?>
                    <div class="when-boxed" data-when-boxed>
                        <?= $slider('sheet_width', Tokens::SHEET_WIDTH_MIN, Tokens::SHEET_WIDTH_MAX, Tokens::SHEET_WIDTH_STEP) ?>
                        <?= $segmented('frame', $labels('frame', array_keys(Tokens::FRAME))) ?>
                        <?= $slider('sheet_gap', 0, Tokens::SHEET_GAP_MAX, 1) ?>
                        <?= $segmented('sheet_radius', $labels('sheet_radius', Tokens::SHEET_RADIUS)) ?>
                        <?= $segmented('sheet_shadow', $labels('sheet_shadow', Tokens::SHEET_SHADOW)) ?>
                        <?= $segmented('page_background', $labels('page_background', Tokens::PAGE_BACKGROUND)) ?>
                        <?php /* Or a colour of its own (D-076): it shows only around a boxed page, so
                                 no text ever lands on it and the check gains no pair. */ ?>
                        <?= $ownColour('page_background_colour') ?>
                    </div>
<?php $page = (string) ob_get_clean(); ob_start(); ?>
                    <?php /* TWO QUESTIONS PER PART, because the stored model has two (D-123): how far
                             the BAR reaches — inside the sheet or across the window, a question
                             only a boxed page asks — and what the logo and menu INSIDE it line up
                             with. An answer that would change nothing is not offered
                             (admin-appearance.css), and one already chosen is never hidden. */ ?>
                    <div class="when-boxed" data-when-boxed><?= $segmented('header_bleed', $labels('header_bleed', Tokens::BLEED)) ?></div>
                    <?= $segmented('header_width', $labels('header_width', Tokens::HEADER_WIDTH)) ?>
                    <div class="when-boxed" data-when-boxed><?= $segmented('footer_bleed', $labels('footer_bleed', Tokens::BLEED)) ?></div>
                    <?= $segmented('footer_width', $labels('footer_width', Tokens::FOOTER_WIDTH)) ?>
<?php $chrome = (string) ob_get_clean(); ?>
                <?= $group('layout', 'diagram', $diagram) ?>
                <?= $group('layout', 'content', $content) ?>
                <?= $group('layout', 'page', $page) ?>
                <?= $group('layout', 'chrome', $chrome) ?>
