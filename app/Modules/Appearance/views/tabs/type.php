<?php

use App\Modules\Design\Tokens;
use App\Modules\Design\Typography;

/**
 * The type tab of the Appearance screen (PLAN.md D-059, D-065). Included by appearance.php,
 * whose variables and helpers it reads.
 *
 * NO LEGEND REPEATING THE TAB'S OWN NAME: the strip above already says which of the five
 * this is, and a heading that restates it is furniture.
 *
 * @var array<string, string> $decisions
 * @var array<string, string> $errors
 * @var callable(string): string $error one field's message, or an empty slot for the script
 * @var callable(string, array<string, string>, string=, string=): string $segmented
 * @var callable(string, list<string>): array<string, string> $labels
 * @var array{text: array<string, int>, text_phone: int} $readable
 */
?>
            <fieldset class="fieldset">
                <?php /* THE PAIRING IS CARDS, NOT A LIST OF NAMES. "Modern" means nothing on
                         its own; "Modern · Inter" is a choice a person can make, and the
                         sample is set in the face itself — the ONE place the admin may show
                         the site's typefaces, because they are what is being chosen. */ ?>
                <div class="field">
                    <span class="field-label" id="design-typography-label"><?= e(t('design.typography')) ?></span>
                    <div class="typefaces" role="radiogroup" aria-labelledby="design-typography-label">
<?php foreach (Typography::PAIRINGS as $name => $pairing): ?>
<?php
    $heading = Typography::FAMILIES[$pairing['heading']]['name'];
    $body = Typography::FAMILIES[$pairing['body']]['name'];
?>
                        <label class="typeface">
                            <input type="radio" name="typography" value="<?= e($name) ?>"<?= $decisions['typography'] === $name ? ' checked' : '' ?>>
                            <span class="typeface-sample" data-typeface="<?= e($name) ?>" aria-hidden="true">Aa</span>
                            <span class="typeface-name"><?= e(t('design.typography.' . $name)) ?>
                                <span><?= e($heading === $body ? $heading : $heading . ' / ' . $body) ?></span></span>
                        </label>
<?php endforeach; ?>
                    </div>
                    <?= field_hint('hint.design.typography') ?>
                    <?= $error('typography') ?>
                </div>

                <?php /* SIZE FIRST, THEN SCALE. They are different questions and were one
                         control for too long: the scale is how much bigger each heading is
                         than the one under it, and this is how big the text itself is
                         (D-062). */ ?>
                <?= $segmented('text_size', $labels('text_size', array_keys(Tokens::TEXT_SIZE)), $readable['text']['base'] . 'px') ?>
                <?= $segmented('scale', $labels('scale', Tokens::SCALES), $readable['text']['2xl'] . 'px') ?>

                <?php /* THE SPECIMEN (D-065). Not the site's own typeface at the site's own
                         size — the admin may not take those — but the SIZES, which is what
                         the two controls above decide and what the line of numbers used to
                         say in words. Set in the admin's face, labelled with the pixels the
                         site will use. */ ?>
                <div class="specimen" aria-hidden="true">
<?php foreach ([['4xl', 'design.specimen.hero'], ['2xl', 'design.specimen.text_heading'], ['base', 'design.specimen.text_body'], ['sm', 'design.specimen.small']] as [$step, $key]): ?>
                    <p class="specimen-line specimen-<?= e($step) ?>" data-specimen="<?= e($step) ?>">
                        <span><?= e(t($key)) ?></span><em data-specimen-size="<?= e($step) ?>"><?= e($readable['text'][$step] . 'px') ?></em>
                    </p>
<?php endforeach; ?>
                </div>
                <p class="derived"><?= e(t('design.readable.phone', ['phone' => $readable['text_phone'] . 'px'])) ?></p>
            </fieldset>
