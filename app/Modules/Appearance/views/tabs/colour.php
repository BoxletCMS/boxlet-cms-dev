<?php

/**
 * The colour tab of the Appearance screen (PLAN.md D-059). Included by appearance.php, whose
 * variables and helpers it reads: $decisions, $errors, $colors, $pairs, $readable, and the
 * $segmented, $labels, $error and $swatch closures.
 *
 * NO LEGEND REPEATING THE TAB'S OWN NAME: the strip above already says which of the five
 * this is, and a heading that restates it is furniture.
 *
 * @var array<string, string> $decisions
 * @var array<string, string> $errors
 * @var callable(string): string $error one field's message, or an empty slot for the script
 * @var callable(string, array<array-key, string>, string=, string=): string $segmented
 * @var callable(string, list<string>): array<string, string> $labels
 * @var array<string, string> $colors
 * @var list<array{pair: string, decision: string, ratio: float, required: float, passes: bool, foreground: string, background: string}> $pairs
 * @var callable(string, string): string $swatch
 * @var bool $handSet whether any colour is the owner's, which is why the panel starts open
 */
/**
 * One row of the contrast gauge (D-058): the pair as it would actually look, then its name,
 * then what it measures. The specimen is an SVG rather than a styled box, because the admin's
 * policy forbids a style attribute and its stylesheet may not hold a site colour.
 *
 * @param array{pair: string, ratio: float, passes: bool, foreground: string, background: string} $pair
 */
$gaugeRow = static function (array $pair): string {
    $ratio = number_format($pair['ratio'], 2);

    return '<li class="gauge-row' . ($pair['passes'] ? '' : ' gauge-fails') . '" data-pair="' . e($pair['pair']) . '">'
        . '<svg class="gauge-sample" viewBox="0 0 40 24" aria-hidden="true">'
        . '<rect width="40" height="24" fill="' . e($pair['background']) . '" data-pair-background/>'
        . '<text x="20" y="17" text-anchor="middle" font-size="13" fill="' . e($pair['foreground']) . '" data-pair-foreground>' . e(t('design.contrast.sample')) . '</text>'
        . '</svg>'
        . '<span class="gauge-name">' . e(t('design.pair.' . $pair['pair'])) . '</span>'
        . '<span class="gauge-ratio"><span data-pair-ratio>' . e($ratio) . '</span>:1'
        . ' <span class="gauge-verdict" data-pair-verdict data-pass="' . e(t('design.contrast.pass')) . '" data-fail="' . e(t('design.contrast.fail')) . '">'
        . e(t($pair['passes'] ? 'design.contrast.pass' : 'design.contrast.fail')) . '</span></span>'
        . '</li>';
};
/* The six that matter most stand open; the rest fold away — but a pair that FAILS is never
   folded, or the screen would hide the one thing the owner has to act on. */
$openPairs = [];
$foldedPairs = [];
foreach ($pairs as $index => $pair) {
    if ($index < 6 || !$pair['passes']) {
        $openPairs[] = $pair;
        continue;
    }
    $foldedPairs[] = $pair;
}
?>
            <fieldset class="fieldset">
                <div class="field">
                    <label for="design-seed"><?= e(t('design.seed')) ?></label>
                    <div class="colour-field">
                        <input type="color" class="colour-input" id="design-seed" name="seed" value="<?= e($decisions['seed']) ?>">
                        <output class="colour-value" for="design-seed" data-colour-for="design-seed"><?= e($decisions['seed']) ?></output>
                    </div>
                    <span class="hint"><?= e(t('design.seed_hint')) ?></span>
                    <?= $error('seed') ?>
                </div>
                <div class="field">
                    <label class="checkbox"><input type="checkbox" name="use_secondary" value="1"<?= $decisions['secondary'] !== '' ? ' checked' : '' ?>> <span><?= e(t('design.use_secondary')) ?></span></label>
                    <div class="colour-field">
                        <input type="color" class="colour-input" id="design-secondary" name="secondary" value="<?= e($decisions['secondary'] !== '' ? $decisions['secondary'] : $colors['contrast']) ?>" aria-label="<?= e(t('design.secondary')) ?>">
                        <output class="colour-value" for="design-secondary" data-colour-for="design-secondary"><?= e($decisions['secondary'] !== '' ? $decisions['secondary'] : $colors['contrast']) ?></output>
                    </div>
                    <?= field_hint('hint.design.secondary') ?>
                    <?= $error('secondary') ?>
                </div>
                <?= $segmented('surface_contrast', $labels('surface_contrast', App\Modules\Design\Tokens::SURFACE_CONTRAST)) ?>
                <div class="field">
                    <span class="field-label"><?= e(t('design.derived_colours')) ?></span>
                    <ul class="swatches">
<?php foreach ($colors as $name => $hex): ?>
                        <li><?= $swatch($hex, $name) ?><span><?= e(t('design.color.' . $name)) ?></span><code data-swatch-value="<?= e($name) ?>"><?= e($hex) ?></code></li>
<?php endforeach; ?>
                    </ul>
                </div>

                <?php /* COLOURS BY HAND (D-063), folded away because they are a DISAGREEMENT
                         with the palette rather than a step in setting one up: the two above
                         work out all fifteen, and this is for the owner who wants one of them
                         to be something else.
                         Only the six independent roles are here. The inks that go ON a colour
                         — on the accent, on the contrast surface, on the gradient — stay
                         computed, because choosing them is choosing whether text can be read.
                         Each says what the palette would otherwise give, so taking one over
                         starts from the answer rather than from black. */ ?>
                <details class="by-hand"<?= $handSet ? ' open' : '' ?>>
                    <summary><?= e(t('design.by_hand')) ?></summary>
                    <p class="hint"><?= e(t('design.by_hand_intro')) ?></p>
<?php foreach (App\Modules\Design\Palette::BY_HAND as $role): ?>
<?php $field = 'color_' . $role; ?>
                    <div class="field">
                        <label for="design-<?= e($field) ?>"><?= e(t('design.by_hand.' . $role)) ?></label>
                        <div class="colour-field">
                            <input type="color" class="colour-input" id="design-<?= e($field) ?>" name="<?= e($field) ?>"
                                   value="<?= e($decisions[$field] !== '' ? $decisions[$field] : $colors[$role]) ?>"
                                   data-by-hand="<?= e($field) ?>">
                            <output class="colour-value" for="design-<?= e($field) ?>" data-colour-for="design-<?= e($field) ?>"><?= e($decisions[$field] !== '' ? $decisions[$field] : $colors[$role]) ?></output>
                            <?php /* The switch is what makes it the owner's: the colour input
                                     always carries SOME colour, so "is this mine or the
                                     palette's" cannot be read off its value. */ ?>
                            <label class="checkbox by-hand-on">
                                <input type="checkbox" name="<?= e($field) ?>_on" value="1"<?= $decisions[$field] !== '' ? ' checked' : '' ?> data-by-hand-switch="<?= e($field) ?>">
                                <span><?= e(t('design.by_hand.mine')) ?></span>
                            </label>
                        </div>
                        <?= $error($field) ?>
                    </div>
<?php endforeach; ?>
                </details>

                <?php /* THE GAUGE (D-058). Until now the screen could only say that a pair
                         FAILED, which made a palette passing by a hundredth look exactly like
                         one passing easily — and nobody can aim at a number they are never
                         shown. Every pair is measured on the server and listed here; Save
                         still refuses the same ones it always did. */ ?>
                <div class="field gauge" data-gauge>
                    <span class="field-label"><?= e(t('design.contrast.title')) ?></span>
                    <span class="hint"><?= e(t('design.contrast.intro')) ?></span>
                    <ul class="gauge-list" data-gauge-open>
<?php foreach ($openPairs as $pair): ?>
                        <?= $gaugeRow($pair) ?>
<?php endforeach; ?>
                    </ul>
<?php if ($foldedPairs !== []): ?>
                    <details class="gauge-more">
                        <summary><?= e(t('design.contrast.more', ['count' => count($foldedPairs)])) ?></summary>
                        <ul class="gauge-list" data-gauge-folded>
<?php foreach ($foldedPairs as $pair): ?>
                            <?= $gaugeRow($pair) ?>
<?php endforeach; ?>
                        </ul>
                    </details>
<?php endif; ?>
                </div>
            </fieldset>
