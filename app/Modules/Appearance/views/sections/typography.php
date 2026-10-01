<?php

use App\Modules\Design\Tokens;
use App\Support\Controls;

/**
 * Typography (PLAN.md D-157): the pairing, the sizes, the headings, and the three nudges
 * folded away. Included by appearance.php inside its section, with the closures of
 * parts/controls.php in scope.
 *
 * @var array<string, string> $decisions
 * @var array<string, string> $readouts
 * @var Closure(string, array<array-key, string>): string $segmented
 * @var Closure(string, float, float, float, array<array-key, string>=): string $slider
 * @var Closure(string, list<string>): array<string, string> $labels
 * @var Closure(string, string, array<string, mixed>=): array<string, mixed> $rowOptions
 * @var Closure(string, string, string, bool=): string $group
 * @var Closure(string=): string $typefaceCards
 */

/*
 * THE HEADING TREATMENT keeps its "as the typeface" answer in this phase (D-158): what the
 * pairing gives — a weight of 650, a tracking of −0.025em — is not one of the answers
 * offered, so it cannot be shown pressed the way a character's header choice is. It
 * becomes a slider in phase 2, starting at the pairing's own number.
 */
/** @var array<array-key, string> $weights PHP turns '600' into 600 */
$weights = ['' => t('design.heading_weight.follow')];
foreach (Tokens::HEADING_WEIGHTS as $weight) {
    $weights[(string) $weight] = $weight;
}

ob_start();
?>
                    <?= Controls::row(t('design.typography'), $typefaceCards(), $rowOptions('typography', t('design.typography'), ['labelId' => 'design-typography-label'])) ?>
<?php $typeface = (string) ob_get_clean(); ob_start(); ?>
                    <?php /* SIZE FIRST, THEN SCALE: how big the text is, then how much bigger each
                             heading is than the one under it (D-062, D-066). */ ?>
                    <?= $segmented('text_size', $labels('text_size', array_keys(Tokens::TEXT_SIZE))) ?>
                    <?= $slider('scale', Tokens::SCALE_MIN, Tokens::SCALE_MAX, 0.005) ?>
                    <?php /* THE SPECIMEN, DRAWN RATHER THAN DESCRIBED (D-065, D-075): the lines at
                             the sizes the page will really use, shrunk together to fit, in the
                             pairing being chosen. The numbers beside them are the server's. */ ?>
                    <div class="specimen" data-typeface="<?= e($decisions['typography']) ?>" aria-hidden="true">
<?php foreach ([['4xl', 'design.specimen.hero', 'heading'], ['2xl', 'design.specimen.text_heading', 'heading'], ['base', 'design.specimen.text_body', 'body'], ['sm', 'design.specimen.small', 'body']] as [$step, $key, $half]): ?>
                        <p class="specimen-line specimen-<?= e($step) ?> specimen-<?= e($half) ?>" data-specimen="<?= e($step) ?>">
                            <span><?= e(t($key)) ?></span><em data-readout="specimen.<?= e($step) ?>" data-specimen-size="<?= e($step) ?>"><?= e($readouts['specimen.' . $step] ?? '') ?></em>
                        </p>
<?php endforeach; ?>
                    </div>
                    <p class="derived" data-readout="phone"><?= e($readouts['phone'] ?? '') ?></p>
<?php $sizes = (string) ob_get_clean(); ob_start(); ?>
                    <?= $segmented('heading_weight', $weights) ?>
                    <?= $segmented('tracking', ['' => t('design.tracking.follow')] + $labels('tracking', array_keys(Tokens::TRACKING))) ?>
                    <?= $segmented('caps', ['' => t('design.caps.follow')] + $labels('caps', array_keys(Tokens::CAPS))) ?>
<?php $headings = (string) ob_get_clean(); ob_start(); ?>
                    <?php /* The three exceptions to the scale (D-066): a ratio cannot say "that
                             headline, two pixels smaller". */ ?>
<?php foreach (Tokens::NUDGES as $key => $bounds): ?>
                    <?= $slider($key, (float) $bounds['min'], (float) $bounds['max'], 1) ?>
<?php endforeach; ?>
<?php $fine = (string) ob_get_clean(); ?>
                <?= $group('typography', 'typeface', $typeface) ?>
                <?= $group('typography', 'sizes', $sizes) ?>
                <?= $group('typography', 'headings', $headings) ?>
                <?= $group('typography', 'fine', $fine, false) ?>
