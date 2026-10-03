<?php

use App\Modules\Settings\ChromeWords;
use App\Modules\Settings\SiteChrome;

/**
 * NAVIGATION'S FOOTER (D-180): each column's menu, and in each language each column's title
 * and words and the small print. Moved from Appearance's Footer section, which keeps how they
 * look and how many columns are drawn. Required by navigation.php, with the closures of
 * parts/words.php in scope.
 *
 * @var Closure(string, string, string): string $wordsPanel
 * @var Closure(string, string): string $word
 * @var array<int, array<string, mixed>> $locales
 * @var array<int, string> $footerMenus each column's menu: `header`, `none`, or a name (D-115)
 * @var list<string> $menuNames every menu name on offer
 * @var array<string, array<int, array{title: string, depth: int, published: bool, url: string}>> $linkPages
 */

/**
 * RICH TEXT, WITH A SHORT TOOLBAR (D-113): bold, italic, a link, and undo. No headings, lists
 * or quotations — the footer's whitelist (RichText::INLINE) would throw them away, and the
 * toolbar offers only what can be stored, as the page editor's does (D-017). The textarea is
 * the real field; richtext.js puts the editor above it and the plain toggle shows it again.
 * Without a script it is a textarea of HTML, which still saves.
 *
 * The link panel's address box is TEXT, not type="url": an email or a phone number is a link
 * as typed (D-039), and a url input holding one made the whole form refuse to submit.
 */
$richInline = static function (string $name, string $value, string $code, string $hintId) use ($linkPages): string {
    $html = '<div class="richtext" data-richtext>'
        . '<div class="richtext-toolbar" data-richtext-toolbar role="toolbar" aria-label="' . e(t('richtext.toolbar')) . '">'
        . '<div class="rt-group">'
        . '<button type="button" class="rt-button" data-rt="bold" aria-pressed="false" title="' . e(t('richtext.bold')) . '"><span aria-hidden="true">B</span><span class="visually-hidden">' . e(t('richtext.bold')) . '</span></button>'
        . '<button type="button" class="rt-button rt-italic" data-rt="italic" aria-pressed="false" title="' . e(t('richtext.italic')) . '"><span aria-hidden="true">I</span><span class="visually-hidden">' . e(t('richtext.italic')) . '</span></button>'
        . '<button type="button" class="rt-button" data-rt="link" aria-pressed="false" title="' . e(t('richtext.link')) . '"><svg class="rt-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M10.5 13.5a4.5 4.5 0 0 0 6.36 0l2.83-2.83a4.5 4.5 0 0 0-6.36-6.36l-1.06 1.06"/><path d="M13.5 10.5a4.5 4.5 0 0 0-6.36 0l-2.83 2.83a4.5 4.5 0 0 0 6.36 6.36l1.06-1.06"/></svg><span class="visually-hidden">' . e(t('richtext.link')) . '</span></button>'
        . '</div>'
        . '<div class="rt-group rt-history">'
        . '<button type="button" class="rt-button" data-rt="undo" title="' . e(t('richtext.undo')) . '"><span aria-hidden="true">&#8630;</span><span class="visually-hidden">' . e(t('richtext.undo')) . '</span></button>'
        . '<button type="button" class="rt-button" data-rt="redo" title="' . e(t('richtext.redo')) . '"><span aria-hidden="true">&#8631;</span><span class="visually-hidden">' . e(t('richtext.redo')) . '</span></button>'
        . '</div></div>'
        . '<div class="richtext-link" data-richtext-link hidden>'
        . '<input type="text" class="rt-link-text" placeholder="' . e(t('pages.field.link_label_part')) . '" aria-label="' . e(t('pages.field.link_label_part')) . '">'
        . '<select class="rt-link-page" aria-label="' . e(t('richtext.page')) . '"><option value="">' . e(t('pages.field.link_address')) . '</option>';
    foreach ($linkPages[$code] ?? [] as $group => $choice) {
        $html .= '<option value="' . e(\App\Modules\Pages\PageLinks::to($group)) . '" data-title="' . e($choice['title']) . '">' . e(str_repeat('— ', $choice['depth']) . $choice['title'] . ($choice['published'] ? '' : ' ' . t('pages.field.link_page_draft'))) . '</option>';
    }
    $html .= '</select>'
        . '<input type="text" inputmode="url" class="rt-link-input" placeholder="' . e(t('richtext.url_placeholder')) . '" aria-label="' . e(t('richtext.url')) . '">'
        . '<button type="button" class="button button-secondary" data-rt-link="apply">' . e(t('richtext.link')) . '</button>'
        . '<button type="button" class="button button-ghost" data-rt-link="remove">' . e(t('richtext.unlink')) . '</button>'
        . '</div>'
        . '<textarea id="' . e($name) . '" name="' . e($name) . '" rows="3" data-richtext-source aria-describedby="' . e($hintId) . '">' . e($value) . '</textarea>'
        . '<div class="richtext-actions">'
        . '<button type="button" class="button button-ghost js-only" data-richtext-toggle data-label-plain="' . e(t('richtext.plain')) . '" data-label-rich="' . e(t('richtext.rich')) . '">' . e(t('richtext.plain')) . '</button>'
        . '</div></div>';

    return $html;
};
?>
            <section class="panel stack navigation-part" aria-labelledby="navigation-footer-title">
                <h2 id="navigation-footer-title"><?= e(t('navigation.footer')) ?></h2>
                <p class="hint"><?= e(t('navigation.footer_hint')) ?></p>
                    <?php /* Each column's menu (D-115): none, the header's, or a menu of its own,
                             by name, so each language's menu of that name is the column's. */ ?>
<?php foreach (range(1, SiteChrome::FOOTER_COLUMNS) as $n): ?>
<?php $chosen = $footerMenus[$n] ?? ''; ?>
                    <div class="field">
                        <label for="footer_menu_<?= e((string) $n) ?>"><?= e(t('chrome.footer_column_menu', ['n' => (string) $n])) ?></label>
                        <select id="footer_menu_<?= e((string) $n) ?>" name="footer_menu_<?= e((string) $n) ?>" aria-describedby="footer_menu_<?= e((string) $n) ?>-hint">
                            <option value="<?= e(SiteChrome::FOOTER_MENU_NONE) ?>"<?= $chosen === SiteChrome::FOOTER_MENU_NONE || $chosen === '' ? ' selected' : '' ?>><?= e(t('chrome.menu_none')) ?></option>
                            <option value="<?= e(SiteChrome::FOOTER_MENU_HEADER) ?>"<?= $chosen === SiteChrome::FOOTER_MENU_HEADER ? ' selected' : '' ?>><?= e(t('chrome.footer_menu_same')) ?></option>
<?php foreach ($menuNames as $name): ?>
                            <option value="<?= e($name) ?>"<?= $chosen === $name ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach; ?>
                        </select>
                        <span class="hint" id="footer_menu_<?= e((string) $n) ?>-hint"><?= e($n === 1 ? t('chrome.footer_menu_hint') : t('chrome.footer_column_menu_hint')) ?></span>
                    </div>
<?php endforeach; ?>
<?php foreach ($locales as $locale): ?>
<?php
    $code = (string) $locale['code'];
    $field = static fn (string $name): string => ChromeWords::field($name, $code);
    ob_start();
?>
<?php foreach (ChromeWords::COLUMN_FIELDS as $n => [$titleName, $textName]): ?>
                    <?php /* One column's words (D-115): a title and a line or two under it. The
                             arrangement says whether the column is drawn; what is typed for a
                             column that is not drawn is kept. */ ?>
                    <div class="stack footer-column-words">
                        <p class="field-label"><?= e(t('chrome.footer_column', ['n' => (string) $n])) ?></p>
                        <div class="field">
                            <label for="<?= e($field($titleName)) ?>"><?= e(t('chrome.footer_column_title')) ?></label>
                            <input type="text" id="<?= e($field($titleName)) ?>" name="<?= e($field($titleName)) ?>"
                                   maxlength="80" value="<?= e($word($code, $titleName)) ?>">
                        </div>
                        <div class="field">
                            <label for="<?= e($field($textName)) ?>"><?= e(t('chrome.text')) ?></label>
                            <?= $richInline($field($textName), $word($code, $textName), $code, $field($textName) . '-hint') ?>
                            <span class="hint" id="<?= e($field($textName)) ?>-hint"><?= e(t('chrome.text_hint')) ?></span>
                        </div>
                    </div>
<?php endforeach; ?>

                    <div class="field">
                        <label for="<?= e($field('small_print')) ?>"><?= e(t('chrome.small_print')) ?></label>
                        <input type="text" id="<?= e($field('small_print')) ?>" name="<?= e($field('small_print')) ?>"
                               maxlength="255" value="<?= e($word($code, 'small_print')) ?>"
                               aria-describedby="<?= e($field('small_print')) ?>-hint">
                        <span class="hint" id="<?= e($field('small_print')) ?>-hint"><?= e(t('chrome.small_print_hint')) ?></span>
                    </div>
<?= $wordsPanel($code, t('chrome.words.footer'), (string) ob_get_clean()) ?>
<?php endforeach; ?>
            </section>
