<?php

use App\Modules\Pages\PageLinks;
use App\Modules\Settings\ChromeWords;
use App\Support\Url;

/**
 * Header (PLAN.md D-111, D-157): how it is arranged, what it does on scroll, what it stands
 * on, how its menu and button are set — and, until they move to Navigation, its words. How
 * far it reaches is in Layout & widths, with every other width.
 *
 * Included by appearance.php inside its section, with the closures of parts/controls.php
 * and parts/words.php in scope.
 *
 * @var Closure(string): string $error
 * @var Closure(string): string $lookGroup one chrome choice as a row of buttons
 * @var Closure(string): string $ownColour
 * @var Closure(string, string, string): string $wordsPanel
 * @var Closure(string, string): string $word
 * @var Closure(string, string, string, bool=): string $group
 * @var string $menu the menu the header shows, by name
 * @var list<string> $menus every menu name on offer
 * @var array<string, array<int, array{title: string, depth: int, published: bool, url: string}>> $linkPages
 * @var array<int, array<string, mixed>> $locales
 */
ob_start();
?>
                    <?php /* The logo is the site's, not the header's: one setting, under
                             Settings → Branding (D-038). */ ?>
                    <p class="hint"><?= e(t('chrome.logo_where')) ?> <a href="<?= e(Url::admin('settings')) ?>"><?= e(t('chrome.logo_where_link')) ?></a></p>
                    <?= $lookGroup('header_arrangement') ?>
                    <?= $lookGroup('brand') ?>
                    <?php /* A logo's size means nothing where the header shows only the name, so
                             it is offered only where there is a logo to size (admin-appearance.css). */ ?>
                    <?= $lookGroup('logo_size') ?>
                    <?= $lookGroup('density') ?>
<?php $arrangement = (string) ob_get_clean(); ob_start(); ?>
                    <?= $lookGroup('header_surface') ?>
                    <?= $ownColour('header_colour') ?>
                    <?= $lookGroup('header_edge') ?>
<?php $background = (string) ob_get_clean(); ob_start(); ?>
                    <?php /* By NAME, not by id: the same name is each language's own menu, so this
                             is one choice rather than one per translation (D-030). */ ?>
                    <div class="field">
                        <label for="header_menu"><?= e(t('chrome.menu')) ?></label>
                        <select id="header_menu" name="header_menu" aria-describedby="header_menu-hint">
                            <option value=""><?= e(t('chrome.menu_none')) ?></option>
<?php foreach ($menus as $name): ?>
                            <option value="<?= e($name) ?>"<?= $menu === $name ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach; ?>
                        </select>
                        <span class="hint" id="header_menu-hint"><?= e($menus === [] ? t('chrome.no_menus') : t('chrome.menu_hint')) ?></span>
                    </div>
                    <?= $lookGroup('nav_style') ?>
                    <?= $lookGroup('nav_ink') ?>
                    <?= $lookGroup('header_button') ?>
<?php $menuGroup = (string) ob_get_clean(); ob_start(); ?>
<?php foreach ($locales as $locale): ?>
<?php
    $code = (string) $locale['code'];
    $field = static fn (string $name): string => ChromeWords::field($name, $code);
    // A page first, an address second (D-034), exactly as in a block's link field: choosing
    // a page shows its address and offers its title as the label (D-038).
    $buttonGroup = PageLinks::reference($word($code, 'button_url'));
    $buttonPages = $linkPages[$code] ?? [];
    $buttonChoice = $buttonGroup === null ? null : ($buttonPages[$buttonGroup] ?? false);
    $buttonAddress = $buttonGroup === null ? $word($code, 'button_url') : (is_array($buttonChoice) ? $buttonChoice['url'] : '');
    $buttonReadonly = $buttonGroup === null ? '' : ' readonly';
    ob_start();
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
<?php foreach ($buttonPages as $pageGroup => $choice): ?>
                                <option value="<?= e((string) $pageGroup) ?>" data-url="<?= e($choice['url']) ?>" data-title="<?= e($choice['title']) ?>"<?= $pageGroup === $buttonGroup ? ' selected' : '' ?>><?= e(str_repeat('— ', $choice['depth']) . $choice['title'] . ($choice['published'] ? '' : ' ' . t('pages.field.link_page_draft'))) ?></option>
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
<?= $wordsPanel($code, t('chrome.words.header'), (string) ob_get_clean()) ?>
<?php endforeach; ?>
<?php $wordsGroup = (string) ob_get_clean(); ?>
                <?= $group('header', 'arrangement', $arrangement) ?>
                <?= $group('header', 'behaviour', $lookGroup('header_behaviour')) ?>
                <?= $group('header', 'background', $background) ?>
                <?= $group('header', 'menu', $menuGroup) ?>
                <?= $group('header', 'words', $wordsGroup) ?>
