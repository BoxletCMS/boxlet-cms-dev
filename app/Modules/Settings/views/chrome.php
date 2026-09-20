<?php

use App\Modules\Settings\ChromeController;
use App\Support\Url;

/**
 * The site's header and footer (PLAN.md D-028, D-030). Provided by AdminView::render().
 *
 * ONE FORM. Everything here is a setting, unlike the settings screen where the maintenance
 * switch has to post elsewhere because it writes a file.
 *
 * The shared choices come first and the words follow, grouped by language, because that is
 * the order the questions arrive in: which picture and which menu is one decision for the
 * site, and then the same four fields are answered once per language.
 *
 * @var array{menu: string, locales: array<string, array<string, string>>, look: array<string, string>} $values
 * @var array<string, string> $errors
 * @var list<string> $menus
 * @var array<string, string> $characterLook what the active character gives each look choice
 * @var array<string, array<int, array{title: string, depth: int, published: bool, url: string}>> $linkPages
 *      per locale, page group => what the button may point at (PLAN.md D-034)
 * @var array<int, array<string, mixed>> $locales
 * @var string $title
 * @var string $csrf
 */
$error = static fn (string $key): string => isset($errors[$key])
    ? '<p class="field-error" role="alert">' . e($errors[$key]) . '</p>'
    : '';

$word = static fn (string $code, string $field): string => is_string($values['locales'][$code][$field] ?? null)
    ? $values['locales'][$code][$field]
    : '';

?>
        <div class="page-header">
            <h1><?= e($title) ?></h1>
        </div>
        <p class="page-subtitle"><?= e(t('chrome.intro')) ?></p>

        <form method="post" action="<?= e(Url::admin('chrome')) ?>">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

            <div class="panel stack">
                <h2><?= e(t('chrome.shared')) ?></h2>

                <?php /* The posted names are header_* and footer_*, not chrome_*: a settings
                         key and a form field are different namespaces, and giving them one
                         prefix meant neither a reader nor chrome_test.php could tell them
                         apart. The key shape stays SiteChrome's alone. */ ?>
                <?php /* The logo is the site's, not the header's: one setting, under Settings →
                         Branding, with the favicon and the sharing picture (D-038). */ ?>
                <p class="hint"><?= e(t('chrome.logo_where')) ?> <a href="<?= e(Url::admin('settings')) ?>"><?= e(t('chrome.logo_where_link')) ?></a></p>

                <?php /* By NAME, not by id: the same name is each language's own menu, so this
                         is one choice rather than one per translation (D-030). A menu made
                         and deleted and made again under that name simply works. */ ?>
                <div class="field">
                    <label for="header_menu"><?= e(t('chrome.menu')) ?></label>
                    <select id="header_menu" name="header_menu" aria-describedby="header_menu-hint">
                        <option value=""><?= e(t('chrome.menu_none')) ?></option>
<?php foreach ($menus as $name): ?>
                        <option value="<?= e($name) ?>"<?= $values['menu'] === $name ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach; ?>
                    </select>
                    <span class="hint" id="header_menu-hint">
                        <?= e($menus === [] ? t('chrome.no_menus') : t('chrome.menu_hint')) ?>
                    </span>
                </div>
            </div>

            <?php /* How the chrome looks (D-032, D-036). Every choice starts "as the
                     character has it", which names what that currently is, so leaving it
                     alone is a choice the owner can read rather than a blank. */ ?>
            <div class="panel stack">
                <h2><?= e(t('chrome.look')) ?></h2>
                <p class="hint"><?= e(t('chrome.look_intro')) ?></p>
                <div class="look-grid">
<?php foreach (\App\Modules\Settings\ChromeLook::OPTIONS as $choice => $options): ?>
<?php $field = \App\Modules\Settings\ChromeLook::field($choice); ?>
                    <div class="field">
                        <label for="<?= e($field) ?>"><?= e(t('chrome.look.' . $choice)) ?></label>
                        <select id="<?= e($field) ?>" name="<?= e($field) ?>">
                            <option value=""><?= e(t('chrome.look.follow', ['value' => t('chrome.look.' . $choice . '.' . ($characterLook[$choice] ?? ''))])) ?></option>
<?php foreach ($options as $option): ?>
                            <option value="<?= e($option) ?>"<?= ($values['look'][$choice] ?? '') === $option ? ' selected' : '' ?>><?= e(t('chrome.look.' . $choice . '.' . $option)) ?></option>
<?php endforeach; ?>
                        </select>
                        <?= field_hint('hint.look.' . $choice) ?>
                    </div>
<?php endforeach; ?>
                </div>
            </div>

<?php foreach ($locales as $locale): ?>
<?php $code = (string) $locale['code']; ?>
            <div class="panel stack">
                <h2><?= e(t('chrome.words')) ?>: <?= e((string) $locale['label']) ?></h2>

<?php $field = static fn (string $name): string => ChromeController::field($name, $code); ?>
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
            </div>
<?php endforeach; ?>

            <button type="submit" class="button"><?= e(t('chrome.save')) ?></button>
        </form>
