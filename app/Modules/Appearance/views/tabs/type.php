<?php

/**
 * The type tab of the Appearance screen (PLAN.md D-059). Included by appearance.php, whose
 * variables and helpers it reads: $decisions, $errors, $colors, $pairs, $readable, and the
 * $select, $labels, $error and $swatch closures.
 *
 * NO LEGEND REPEATING THE TAB'S OWN NAME: the strip above already says which of the five
 * this is, and a heading that restates it is furniture.
 *
 * @var array<string, string> $decisions
 * @var array<string, string> $errors
 * @var callable(string): string $error one field's message, or an empty slot for the script
 * @var callable(string, array<string, string>): string $select
 * @var callable(string, list<string>): array<string, string> $labels
 * @var array{text: array<string, int>, text_phone: int} $readable
 */
/* The pairing's name and what it actually is: "Modern: Inter", so the choice is not a word
   the owner has to have met before. */
$typefaces = [];
foreach (App\Modules\Design\Typography::PAIRINGS as $name => $pairing) {
    $heading = App\Modules\Design\Typography::FAMILIES[$pairing['heading']]['name'];
    $body = App\Modules\Design\Typography::FAMILIES[$pairing['body']]['name'];
    $typefaces[$name] = t('design.typography.' . $name) . ': ' . ($heading === $body ? $heading : $heading . ' / ' . $body);
}
?>
            <fieldset class="fieldset">
                <div class="field">
                    <label for="design-typography"><?= e(t('design.typography')) ?></label>
                    <?= $select('typography', $typefaces) ?>
                    <?= field_hint('hint.design.typography') ?>
                    <?= $error('typography') ?>
                </div>
                <?php /* SIZE FIRST, THEN SCALE. They are different questions and were one
                         control for too long: the scale is how much bigger each heading is
                         than the one under it, and this is how big the text itself is
                         (D-062). */ ?>
                <div class="field">
                    <label for="design-text_size"><?= e(t('design.text_size')) ?></label>
                    <?= $select('text_size', $labels('text_size', array_keys(App\Modules\Design\Tokens::TEXT_SIZE))) ?>
                    <?= field_hint('hint.design.text_size') ?>
                    <?= $error('text_size') ?>
                </div>
                <div class="field">
                    <label for="design-scale"><?= e(t('design.scale')) ?></label>
                    <?= $select('scale', $labels('scale', App\Modules\Design\Tokens::SCALES)) ?>
                    <?= field_hint('hint.design.scale') ?>
                    <?= $error('scale') ?>
                </div>
<?php /* Numbers a person reads, not the compiler's own language: this line used to print
     clamp(2.038rem, 1.508rem + 1.759vw, 2.827rem). The specimen is the preview beside
     it — the admin's own type is fixed and may never follow the site's (D-058). */ ?>
                <p class="derived"><?= e(t('design.readable.text', [
                    'base' => $readable['text']['base'] . 'px',
                    'headings' => implode(' · ', array_map(static fn (int $px): string => $px . 'px', array_slice($readable['text'], 2))),
                    'phone' => $readable['text_phone'] . 'px',
                ])) ?></p>
            </fieldset>
