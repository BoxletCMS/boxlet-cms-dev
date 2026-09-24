<?php

use App\Modules\Settings\ChromeLook;
use App\Modules\Settings\ChromeWords;
use App\Support\Url;

/**
 * The header and footer tab (PLAN.md D-059). Everything that used to be its own screen at
 * /admin/chrome: which menu the header shows, the seven look choices, and the owner's own
 * words in each language.
 *
 * THE WORDS ARE HERE RATHER THAN ON A SCREEN OF THEIR OWN. They were the only reason that
 * screen would have survived the merge, and a second address for one half of a thing is the
 * arrangement this whole rebuild exists to end. The owner asked the question directly and
 * that was the answer.
 *
 * The preview draws the words of the language it renders, which is the site's main one; the
 * others are edited here and seen on the site.
 *
 * @var array<string, string> $look the seven choices, '' for "follow the character"
 * @var string $menu the menu the header shows, by name
 * @var list<string> $menus every menu name on offer
 * @var array<string, array<string, string>> $words the owner's words, per locale
 * @var array<string, array<int, array{title: string, depth: int, published: bool, url: string}>> $linkPages
 * @var array<int, array<string, mixed>> $locales
 * @var string $shownLocale the language the preview draws
 * @var array<string, string> $characterLook what the character gives each choice
 * @var array<string, string> $errors
 * @var callable(string): string $error
 * @var callable(string, string): string $ownColour
 * @var array<string, string> $colors
 * @var array<string, string> $decisions
 */
/* Which palette shade each part is showing right now — what the owner chose, else what the
   character gives. Only so the picker opens on the colour that is there rather than on
   black; nothing is decided here. */
$resolvedSurface = [
    'header_surface' => ($look['header_surface'] ?? '') !== '' ? $look['header_surface'] : ($characterLook['header_surface'] ?? 'plain'),
    'footer_surface' => ($look['footer_surface'] ?? '') !== '' ? $look['footer_surface'] : ($characterLook['footer_surface'] ?? 'plain'),
];
$word = static fn (string $code, string $field): string => is_string($words[$code][$field] ?? null)
    ? $words[$code][$field]
    : '';
?>
            <fieldset class="fieldset">
                <?php /* The logo is the site's, not the header's: one setting, under
                         Settings → Branding, with the favicon and the sharing picture
                         (D-038). */ ?>
                <p class="hint"><?= e(t('chrome.logo_where')) ?> <a href="<?= e(Url::admin('settings')) ?>"><?= e(t('chrome.logo_where_link')) ?></a></p>

                <?php /* By NAME, not by id: the same name is each language's own menu, so
                         this is one choice rather than one per translation (D-030). */ ?>
                <div class="field">
                    <label for="header_menu"><?= e(t('chrome.menu')) ?></label>
                    <select id="header_menu" name="header_menu" aria-describedby="header_menu-hint">
                        <option value=""><?= e(t('chrome.menu_none')) ?></option>
<?php foreach ($menus as $name): ?>
                        <option value="<?= e($name) ?>"<?= $menu === $name ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach; ?>
                    </select>
                    <span class="hint" id="header_menu-hint">
                        <?= e($menus === [] ? t('chrome.no_menus') : t('chrome.menu_hint')) ?>
                    </span>
                </div>

                <?php /* How the chrome looks (D-032, D-036). Every choice can be left to the
                         character, and that is now a STATE you can see rather than a default
                         hidden in a dropdown (D-065, handoff §3.5): the group says
                         "following" while nothing is chosen, and the first segment — named
                         for what the character actually gives — puts it back. */ ?>
<?php foreach (ChromeLook::OPTIONS as $choice => $options): ?>
<?php
    $field = ChromeLook::field($choice);
    $chosen = $look[$choice] ?? '';
    $fromCharacter = t('chrome.look.' . $choice . '.' . ($characterLook[$choice] ?? ''));
?>
                <div class="field">
                    <div class="field-row">
                        <span class="field-label" id="<?= e($field) ?>-label"><?= e(t('chrome.look.' . $choice)) ?></span>
<?php $said = $chosen === '' ? t('chrome.look.following') : t('chrome.look.' . $choice . '.' . $chosen); ?>
                        <span class="readout<?= $chosen === '' ? ' readout-following' : '' ?>" title="<?= e($said) ?>"><?= e($said) ?></span>
                    </div>
                    <div class="segmented-choice" role="radiogroup" aria-labelledby="<?= e($field) ?>-label">
                        <label class="segment segment-follow">
                            <input type="radio" id="<?= e($field) ?>" name="<?= e($field) ?>" value=""<?= $chosen === '' ? ' checked' : '' ?>>
                            <span><?= e(t('chrome.look.follow_short', ['value' => $fromCharacter])) ?></span>
                        </label>
<?php foreach ($options as $option): ?>
                        <label class="segment">
                            <input type="radio" name="<?= e($field) ?>" value="<?= e($option) ?>"<?= $chosen === $option ? ' checked' : '' ?>>
                            <span><?= e(t('chrome.look.' . $choice . '.' . $option)) ?></span>
                        </label>
<?php endforeach; ?>
                    </div>
                    <?= field_hint('hint.look.' . $choice) ?>
                </div>
<?php if ($choice === 'header_surface' || $choice === 'footer_surface'): ?>
                <?php /* Or a colour of its own (D-076), under the group it answers. The ink
                         on it is DERIVED from it (Palette::inksOn), so this is not the free
                         colour §5.4 refuses: it is a surface that brings its own readable
                         text, and the gauge measures it like any other. */ ?>
                <?= $ownColour(
                    str_replace('_surface', '_colour', $choice),
                    $colors[['plain' => 'background', 'tinted' => 'surface', 'contrast' => 'contrast'][$resolvedSurface[$choice]] ?? 'background'],
                ) ?>
<?php endif; ?>
<?php endforeach; ?>
            </fieldset>

<?php foreach ($locales as $locale): ?>
<?php $code = (string) $locale['code']; ?>
            <fieldset class="fieldset">
                <legend><?= e(t('chrome.words')) ?><?= count($locales) > 1 ? ': ' . e((string) $locale['label']) : '' ?></legend>
<?php if (count($locales) > 1 && $code === $shownLocale): ?>
                <p class="hint"><?= e(t('appearance.words_previewed')) ?></p>
<?php endif; ?>

<?php $field = static fn (string $name): string => ChromeWords::field($name, $code); ?>
<?php
    // A page first, an address second (PLAN.md D-034), exactly as in a block's link field:
    // choosing a page shows its address and offers its title as the label (D-038).
    $buttonGroup = \App\Modules\Pages\PageLinks::reference($word($code, 'button_url'));
    $buttonPages = $linkPages[$code] ?? [];
    $buttonChoice = $buttonGroup === null ? null : ($buttonPages[$buttonGroup] ?? false);
    $buttonAddress = $buttonGroup === null ? $word($code, 'button_url') : (is_array($buttonChoice) ? $buttonChoice['url'] : '');
    $buttonReadonly = $buttonGroup === null ? '' : ' readonly';
?>
                <div class="stack" data-link>
                    <div class="field">
                        <label for="<?= e($field('button_page')) ?>"><?= e(t('chrome.button_url')) ?></label>
                        <select id="<?= e($field('button_page')) ?>" name="<?= e($field('button_page')) ?>" data-link-page
                                aria-describedby="<?= e($field('button_url')) ?>-hint">
                            <option value=""<?= $buttonGroup === null ? ' selected' : '' ?>><?= e(t('pages.field.link_address')) ?></option>
<?php if ($buttonChoice === false): ?>
                            <option value="<?= e((string) $buttonGroup) ?>" selected><?= e(t('pages.field.link_page_gone')) ?></option>
<?php endif; ?>
<?php foreach ($buttonPages as $group => $choice): ?>
                            <option value="<?= e((string) $group) ?>" data-url="<?= e($choice['url']) ?>" data-title="<?= e($choice['title']) ?>"<?= $group === $buttonGroup ? ' selected' : '' ?>><?= e(str_repeat('— ', $choice['depth']) . $choice['title'] . ($choice['published'] ? '' : ' ' . t('pages.field.link_page_draft'))) ?></option>
<?php endforeach; ?>
                        </select>
                        <span class="hint" id="<?= e($field('button_url')) ?>-hint"><?= e(t('chrome.button_url_hint')) ?></span>
                    </div>

                    <div class="field">
                        <label for="<?= e($field('button_url')) ?>"><?= e(t('chrome.button_address')) ?></label>
                        <input type="text" id="<?= e($field('button_url')) ?>" name="<?= e($field('button_url')) ?>"
                               maxlength="2048" value="<?= e($buttonAddress) ?>" data-link-address<?= $buttonReadonly ?>
                               placeholder="<?= e(t('pages.field.link_url_input')) ?>" aria-describedby="<?= e($field('button_url')) ?>-address-hint">
                        <span class="hint" id="<?= e($field('button_url')) ?>-address-hint"><?= e(t('chrome.button_address_hint')) ?></span>
                        <?= $error($field('button_url')) ?>
                    </div>

                    <div class="field">
                        <label for="<?= e($field('button_label')) ?>"><?= e(t('chrome.button_label')) ?></label>
                        <input type="text" id="<?= e($field('button_label')) ?>" name="<?= e($field('button_label')) ?>"
                               maxlength="60" value="<?= e($word($code, 'button_label')) ?>" data-link-label
                               aria-describedby="<?= e($field('button_label')) ?>-hint">
                        <span class="hint" id="<?= e($field('button_label')) ?>-hint"><?= e(t('chrome.button_label_hint')) ?></span>
                    </div>
                </div>

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
            </fieldset>
<?php endforeach; ?>
