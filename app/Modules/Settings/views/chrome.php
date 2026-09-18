<?php

use App\Modules\Media\MediaReference;
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
 * @var array{logo: int|null, menu: string, locales: array<string, array<string, string>>} $values
 * @var array<string, string> $errors
 * @var list<array{id: int, name: string, thumb: string|null}> $pictures
 * @var list<string> $menus
 * @var array<string, array<int, array{title: string, depth: int, published: bool}>> $linkPages
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

/** The same chooser the settings screen and the block editor use. Without JavaScript this select IS the control. */
$picker = static function (string $key, ?int $chosen) use ($pictures): string {
    $html = '<select id="' . e($key) . '" name="' . e($key) . '" data-media-field'
        . MediaReference::pickerAttributes() . '>';
    $html .= '<option value="">' . e(t('pages.field.media_none')) . '</option>';
    foreach ($pictures as $picture) {
        $html .= '<option value="' . e($picture['id']) . '"'
            . ($picture['thumb'] === null ? '' : ' data-thumb="' . e($picture['thumb']) . '"')
            . ($chosen === $picture['id'] ? ' selected' : '') . '>'
            . e($picture['name']) . '</option>';
    }

    return $html . '</select>';
};
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
                <div class="field">
                    <label for="header_logo"><?= e(t('chrome.logo')) ?></label>
                    <?= $picker('header_logo', $values['logo']) ?>
                    <span class="hint"><?= e(t('chrome.logo_hint')) ?></span>
                </div>

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

<?php foreach ($locales as $locale): ?>
<?php $code = (string) $locale['code']; ?>
            <div class="panel stack">
                <h2><?= e(t('chrome.words')) ?>: <?= e((string) $locale['label']) ?></h2>

<?php $field = static fn (string $name): string => ChromeController::field($name, $code); ?>
                <div class="field">
                    <label for="<?= e($field('button_label')) ?>"><?= e(t('chrome.button_label')) ?></label>
                    <input type="text" id="<?= e($field('button_label')) ?>" name="<?= e($field('button_label')) ?>"
                           maxlength="60" value="<?= e($word($code, 'button_label')) ?>"
                           aria-describedby="<?= e($field('button_label')) ?>-hint">
                    <span class="hint" id="<?= e($field('button_label')) ?>-hint"><?= e(t('chrome.button_label_hint')) ?></span>
                </div>

<?php
    // A page first, an address second (PLAN.md D-034), exactly as in a block's link field.
    $buttonGroup = \App\Modules\Pages\PageLinks::reference($word($code, 'button_url'));
    $buttonPages = $linkPages[$code] ?? [];
?>
                <div class="field">
                    <label for="<?= e($field('button_page')) ?>"><?= e(t('chrome.button_url')) ?></label>
                    <div class="link-field">
                        <select id="<?= e($field('button_page')) ?>" name="<?= e($field('button_page')) ?>"
                                aria-describedby="<?= e($field('button_url')) ?>-hint">
                            <option value=""<?= $buttonGroup === null ? ' selected' : '' ?>><?= e(t('pages.field.link_address')) ?></option>
<?php if ($buttonGroup !== null && !isset($buttonPages[$buttonGroup])): ?>
                            <option value="<?= e((string) $buttonGroup) ?>" selected><?= e(t('pages.field.link_page_gone')) ?></option>
<?php endif; ?>
<?php foreach ($buttonPages as $group => $choice): ?>
                            <option value="<?= e((string) $group) ?>"<?= $group === $buttonGroup ? ' selected' : '' ?>><?= e(str_repeat('— ', $choice['depth']) . $choice['title'] . ($choice['published'] ? '' : ' ' . t('pages.field.link_page_draft'))) ?></option>
<?php endforeach; ?>
                        </select>
                        <input type="text" class="link-address" id="<?= e($field('button_url')) ?>" name="<?= e($field('button_url')) ?>"
                               maxlength="2048" value="<?= e($buttonGroup === null ? $word($code, 'button_url') : '') ?>"
                               placeholder="<?= e(t('pages.field.link_url_input')) ?>"
                               aria-label="<?= e(t('chrome.button_url') . ': ' . t('pages.field.link_url_input')) ?>">
                    </div>
                    <span class="hint" id="<?= e($field('button_url')) ?>-hint"><?= e(t('chrome.button_url_hint')) ?></span>
                    <?= $error($field('button_url')) ?>
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
