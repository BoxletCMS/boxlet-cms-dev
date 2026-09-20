<?php

/**
 * The shape tab of the Appearance screen (PLAN.md D-059). Included by appearance.php, whose
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
 * @var array{space: int, section: int, radius: int, container: int} $readable
 */
?>
            <fieldset class="fieldset">
                <div class="field">
                    <label for="design-spacing"><?= e(t('design.spacing')) ?></label>
                    <?= $select('spacing', $labels('spacing', array_keys(App\Modules\Design\Tokens::SPACING))) ?>
                    <?= field_hint('hint.design.spacing') ?>
                    <?= $error('spacing') ?>
                </div>
<?php foreach (['radius' => App\Modules\Design\Tokens::RADIUS, 'shadow' => App\Modules\Design\Tokens::SHADOW, 'container' => array_keys(App\Modules\Design\Tokens::CONTAINER)] as $key => $values): ?>
                <div class="field">
                    <label for="design-<?= e($key) ?>"><?= e(t('design.' . $key)) ?></label>
                    <?= $select($key, $labels($key, $values)) ?>
                    <?= field_hint('hint.design.' . $key) ?>
                    <?= $error($key) ?>
                </div>
<?php endforeach; ?>
                <p class="derived"><?= e(t('design.readable.space', [
                    'space' => $readable['space'] . 'px',
                    'section' => $readable['section'] . 'px',
                ])) ?></p>
                <p class="derived"><?= e(t('design.readable.shape', [
                    'radius' => $readable['radius'] . 'px',
                    'container' => $readable['container'] . 'px',
                ])) ?></p>
            </fieldset>
</fieldset>
