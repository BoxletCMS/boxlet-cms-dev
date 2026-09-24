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
 * @var string $footerMenu the footer's own menu: '' for the header's, `none`, or a name (D-113)
 * @var list<string> $menus every menu name on offer
 * @var array<string, array<int, array{title: string, depth: int, published: bool, url: string}>> $linkPages
 */
?>
            <fieldset class="fieldset">
                <?php /* The footer's own menu (D-113): the header's unless the owner names
                         one, or none. By name, like the header's, so each language's menu of
                         that name is the footer's. */ ?>
                <div class="field">
                    <label for="footer_menu"><?= e(t('chrome.footer_menu')) ?></label>
                    <select id="footer_menu" name="footer_menu" aria-describedby="footer_menu-hint">
                        <option value=""<?= $footerMenu === '' ? ' selected' : '' ?>><?= e(t('chrome.footer_menu_same')) ?></option>
                        <option value="<?= e(\App\Modules\Settings\SiteChrome::FOOTER_MENU_NONE) ?>"<?= $footerMenu === \App\Modules\Settings\SiteChrome::FOOTER_MENU_NONE ? ' selected' : '' ?>><?= e(t('chrome.menu_none')) ?></option>
<?php foreach ($menus as $name): ?>
                        <option value="<?= e($name) ?>"<?= $footerMenu === $name ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach; ?>
                    </select>
                    <span class="hint" id="footer_menu-hint"><?= e(t('chrome.footer_menu_hint')) ?></span>
                </div>

                <?= $lookGroup('footer_layout') ?>
                <?= $lookGroup('footer_columns') ?>
                <?= $lookGroup('small_print_row') ?>
                <?= $lookGroup('footer_surface') ?>
                <?= $ownColour('footer_colour') ?>
                <?= $lookGroup('footer_edge') ?>
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
                    <?php /* RICH TEXT, WITH A SHORT TOOLBAR (D-113): bold, italic, a link, and
                             undo. No headings, lists or quotations — the footer's whitelist
                             (RichText::INLINE) would throw them away, and the toolbar offers
                             only what can be stored, as the page editor's does (D-017). The
                             textarea is the real field; richtext.js puts the editor above it
                             and the plain toggle shows it again. Without a script it is a
                             textarea of HTML, which still saves. A text stored before D-113
                             is plain and is handed over as one paragraph with its breaks
                             (ChromeWords::asHtml). */ ?>
                    <div class="richtext" data-richtext>
                        <div class="richtext-toolbar" data-richtext-toolbar role="toolbar" aria-label="<?= e(t('richtext.toolbar')) ?>">
                            <div class="rt-group">
                                <button type="button" class="rt-button" data-rt="bold" aria-pressed="false" title="<?= e(t('richtext.bold')) ?>"><span aria-hidden="true">B</span><span class="visually-hidden"><?= e(t('richtext.bold')) ?></span></button>
                                <button type="button" class="rt-button rt-italic" data-rt="italic" aria-pressed="false" title="<?= e(t('richtext.italic')) ?>"><span aria-hidden="true">I</span><span class="visually-hidden"><?= e(t('richtext.italic')) ?></span></button>
                                <button type="button" class="rt-button" data-rt="link" aria-pressed="false" title="<?= e(t('richtext.link')) ?>"><svg class="rt-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M10.5 13.5a4.5 4.5 0 0 0 6.36 0l2.83-2.83a4.5 4.5 0 0 0-6.36-6.36l-1.06 1.06"/><path d="M13.5 10.5a4.5 4.5 0 0 0-6.36 0l-2.83 2.83a4.5 4.5 0 0 0 6.36 6.36l1.06-1.06"/></svg><span class="visually-hidden"><?= e(t('richtext.link')) ?></span></button>
                            </div>
                            <div class="rt-group rt-history">
                                <button type="button" class="rt-button" data-rt="undo" title="<?= e(t('richtext.undo')) ?>"><span aria-hidden="true">&#8630;</span><span class="visually-hidden"><?= e(t('richtext.undo')) ?></span></button>
                                <button type="button" class="rt-button" data-rt="redo" title="<?= e(t('richtext.redo')) ?>"><span aria-hidden="true">&#8631;</span><span class="visually-hidden"><?= e(t('richtext.redo')) ?></span></button>
                            </div>
                        </div>
                        <div class="richtext-link" data-richtext-link hidden>
                            <select class="rt-link-page" aria-label="<?= e(t('richtext.page')) ?>">
                                <option value=""><?= e(t('pages.field.link_address')) ?></option>
<?php foreach ($linkPages[$code] ?? [] as $group => $choice): ?>
                                <option value="<?= e(\App\Modules\Pages\PageLinks::to($group)) ?>"><?= e(str_repeat('— ', $choice['depth']) . $choice['title'] . ($choice['published'] ? '' : ' ' . t('pages.field.link_page_draft'))) ?></option>
<?php endforeach; ?>
                            </select>
                            <input type="text" inputmode="url" class="rt-link-input" placeholder="<?= e(t('richtext.url_placeholder')) ?>" aria-label="<?= e(t('richtext.url')) ?>">
                            <button type="button" class="button button-secondary" data-rt-link="apply"><?= e(t('richtext.link')) ?></button>
                            <button type="button" class="button button-ghost" data-rt-link="remove"><?= e(t('richtext.unlink')) ?></button>
                        </div>
                        <textarea id="<?= e($field('text')) ?>" name="<?= e($field('text')) ?>" rows="4" data-richtext-source
                                  aria-describedby="<?= e($field('text')) ?>-hint"><?= e(\App\Modules\Settings\ChromeWords::asHtml($word($code, 'text'))) ?></textarea>
                        <div class="richtext-actions">
                            <button type="button" class="button button-ghost js-only" data-richtext-toggle data-label-plain="<?= e(t('richtext.plain')) ?>" data-label-rich="<?= e(t('richtext.rich')) ?>"><?= e(t('richtext.plain')) ?></button>
                        </div>
                    </div>
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
