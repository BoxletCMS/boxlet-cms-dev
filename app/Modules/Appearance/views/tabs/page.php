<?php

/**
 * The page tab of the Appearance screen (PLAN.md D-059). Included by appearance.php, whose
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
 */
?>
            <?php /* Its own group rather than three more controls under Shape (D-031).
                     Shape is about the content — its spacing, corners, shadows and
                     measure; these three are about the page as a whole. Put together
                     they would have turned that group into a drawer for anything left
                     over. */ ?>
            <fieldset class="fieldset">
                <p class="hint"><?= e(t('design.page_hint')) ?></p>
<?php foreach (['header_width' => App\Modules\Design\Tokens::HEADER_WIDTH, 'boxed' => App\Modules\Design\Tokens::BOXED, 'page_background' => App\Modules\Design\Tokens::PAGE_BACKGROUND] as $key => $values): ?>
                <div class="field">
                    <label for="design-<?= e($key) ?>"><?= e(t('design.' . $key)) ?></label>
                    <?= $select($key, $labels($key, $values)) ?>
                    <?= field_hint('hint.design.' . $key) ?>
<?php if ($key === 'page_background'): ?>
                    <span class="hint"><?= e(t('design.page_background_hint')) ?></span>
<?php endif; ?>
                    <?= $error($key) ?>
                </div>
<?php endforeach; ?>
            </fieldset>
</fieldset>
