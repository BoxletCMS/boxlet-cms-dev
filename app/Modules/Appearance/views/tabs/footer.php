<?php

/**
 * The footer tab of the Appearance screen (PLAN.md D-111). Included by appearance.php, whose
 * variables and helpers it reads — see header.php for why the two are apart.
 *
 * Where the footer breaks out of the sheet is a DESIGN decision (Tokens, D-067) and stays
 * stored as one; it is shown here because this is where a person setting up the footer looks
 * for it.
 *
 * @var array<string, string> $decisions
 * @var array<string, string> $errors
 * @var callable(string): string $error
 * @var callable(string, array<array-key, string>, string=, string=): string $segmented
 * @var callable(string, list<string>): array<string, string> $labels
 * @var callable(string): string $lookGroup one chrome choice as a row of buttons
 * @var callable(string): string $ownColour the header's or footer's colour of its own
 * @var callable(string, string, string): string $wordsPanel one language's words, folded or not
 * @var callable(string, string): string $word a stored word
 * @var array<int, array<string, mixed>> $locales
 */
?>
            <fieldset class="fieldset">
                <?= $lookGroup('footer_layout') ?>
                <?= $lookGroup('footer_columns') ?>
                <?= $lookGroup('footer_surface') ?>
                <?= $ownColour('footer_colour') ?>
                <?= $segmented('footer_bleed', $labels('footer_bleed', App\Modules\Design\Tokens::BLEED)) ?>
            </fieldset>

<?php foreach ($locales as $locale): ?>
<?php
    $code = (string) $locale['code'];
    $field = static fn (string $name): string => \App\Modules\Settings\ChromeWords::field($name, $code);
    ob_start();
?>
                <div class="field">
                    <label for="<?= e($field('text')) ?>"><?= e(t('chrome.text')) ?></label>
                    <textarea id="<?= e($field('text')) ?>" name="<?= e($field('text')) ?>" rows="3"
                              aria-describedby="<?= e($field('text')) ?>-hint"><?= e($word($code, 'text')) ?></textarea>
                    <span class="hint" id="<?= e($field('text')) ?>-hint"><?= e(t('chrome.text_hint')) ?></span>
                </div>

                <div class="field">
                    <label for="<?= e($field('small_print')) ?>"><?= e(t('chrome.small_print')) ?></label>
                    <input type="text" id="<?= e($field('small_print')) ?>" name="<?= e($field('small_print')) ?>"
                           maxlength="255" value="<?= e($word($code, 'small_print')) ?>"
                           aria-describedby="<?= e($field('small_print')) ?>-hint">
                    <span class="hint" id="<?= e($field('small_print')) ?>-hint"><?= e(t('chrome.small_print_hint')) ?></span>
                </div>
<?= $wordsPanel($code, t('chrome.words.footer'), (string) ob_get_clean()) ?>
<?php endforeach; ?>
