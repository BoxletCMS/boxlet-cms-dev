<?php

use App\Modules\Design\Palette;
use App\Modules\Design\Presets;
use App\Support\Url;

/**
 * The Appearance screen (PLAN.md D-059): a strip of characters, five tabs of controls on the
 * left, and a picture of the whole site on the right.
 *
 * FIVE TABS RATHER THAN ONE SCROLL. The controls were a column over three thousand pixels
 * tall, and the header and footer were on another screen entirely. Grouped, each tab is a
 * question a person actually asks: what colour, what type, what shape, how the page sits,
 * and what wraps around it.
 *
 * WITHOUT JAVASCRIPT THE TABS ARE LINKS and every panel is on the page, one under the other,
 * exactly as before. appearance.js turns that into a real tablist — roles, arrow keys, one
 * panel at a time. Nothing here is only reachable through a script.
 *
 * @var array<string, string> $decisions
 * @var array<string, string> $errors keyed by the decision or the field at fault
 * @var string|null $notice
 * @var string $character character loaded into the form, '' when none
 * @var string $activeCharacter character the site composes new blocks with
 * @var bool $hasBlocks whether applying a composition would overwrite anything
 * @var array<string, string> $colors derived palette
 * @var list<array{pair: string, decision: string, ratio: float, required: float, passes: bool, foreground: string, background: string}> $pairs
 * @var array{text: array<string, int>, text_phone: int, space: int, section: int, radius: int, container: int, container_rem: float} $readable
 * @var array<string, string> $look the chrome's seven choices, '' for "follow the character"
 * @var string $menu the menu the header shows, by name
 * @var list<string> $menus every menu name on offer
 * @var array<string, array<string, string>> $words the owner's words, per locale
 * @var array<string, array<int, array{title: string, depth: int, published: bool, url: string}>> $linkPages
 * @var array<int, array<string, mixed>> $locales
 * @var string $shownLocale the language the preview draws
 * @var array<string, string> $characterLook what the character gives each look choice
 * @var string $previewUrl
 * @var string $title
 * @var string $csrf
 */
$error = static fn (string $key): string => isset($errors[$key])
    ? '<p class="field-error" data-error-for="' . e($key) . '" role="alert">' . e($errors[$key]) . '</p>'
    : '<p class="field-error" data-error-for="' . e($key) . '" hidden></p>';
$select = static function (string $key, array $labels) use ($decisions): string {
    $html = '<select id="design-' . e($key) . '" name="' . e($key) . '">';
    foreach ($labels as $value => $label) {
        $selected = (string) $value === $decisions[$key] ? ' selected' : '';
        $html .= '<option value="' . e($value) . '"' . $selected . '>' . e($label) . '</option>';
    }

    return $html . '</select>';
};
$labels = static function (string $key, array $values): array {
    $result = [];
    foreach ($values as $value) {
        $result[$value] = t('design.' . $key . '.' . $value);
    }

    return $result;
};
$swatch = static fn (string $hex, string $name): string => '<svg viewBox="0 0 10 10" aria-hidden="true">'
    . '<rect width="10" height="10" fill="' . e($hex) . '" data-swatch="' . e($name) . '"/></svg>';

/** The five tabs, in the order the questions arrive. */
$tabs = ['colour', 'type', 'shape', 'page', 'chrome'];
?>
        <div class="page-header">
            <h1><?= e($title) ?></h1>
        </div>
<?php if ($notice !== null): ?>
        <p class="notice<?= $errors !== [] ? ' notice-error' : '' ?>" role="<?= $errors !== [] ? 'alert' : 'status' ?>"><?= e($notice) ?></p>
<?php endif; ?>

        <section class="characters" aria-labelledby="characters-heading">
            <h2 id="characters-heading"><?= e(t('design.presets')) ?></h2>
            <p class="hint"><?= e(t('design.presets_hint')) ?></p>
            <div class="character-strip">
<?php foreach (Presets::names() as $preset): ?>
<?php
    $tokens = Presets::get($preset);
    $palette = Palette::colors($tokens['seed'], $tokens['secondary'], $tokens['surface_contrast']);
    $shape = Presets::COMPOSITION[$preset]['section'];
    $summary = implode(' · ', [
        t('style.width.' . $shape['width']),
        t('style.rhythm.' . $shape['rhythm']),
        t('style.align.' . $shape['align']),
        t('style.divider.' . Presets::dividerAccent($preset)),
    ]);
?>
                <?php /* A CARD IS NOT ITS OWN FORM ANY MORE (D-059). Each used to post on its
                         own, carrying nothing but the character's name — which was harmless
                         while the screen held only the design, and destructive the moment it
                         also held the header: loading a character posted a screen with every
                         chrome field empty, and the owner's menu and words were read back as
                         cleared. Measured on the copy, where the site lost its header.
                         The button names the one form instead, so loading a character carries
                         the whole screen, with JavaScript or without it. */ ?>
                <div class="character-card<?= $preset === $activeCharacter ? ' character-current' : '' ?>">
                    <h3>
                        <?= e(t('design.preset.' . $preset)) ?>
<?php if ($preset === $activeCharacter): ?>
                        <span class="character-badge"><?= e(t('design.preset.current')) ?></span>
<?php endif; ?>
                    </h3>
                    <div class="character-chips" aria-hidden="true">
                        <?= $swatch($palette['accent'], 'preset-' . $preset . '-accent') ?>
                        <?= $swatch($palette['contrast'], 'preset-' . $preset . '-contrast') ?>
                        <?= $swatch($palette['surface'], 'preset-' . $preset . '-surface') ?>
                    </div>
                    <p class="character-shape"><?= e($summary) ?></p>
                    <p class="hint"><?= e(t('design.preset.' . $preset . '_hint')) ?></p>
                    <button type="submit" form="design-form" name="action" value="preset:<?= e($preset) ?>" class="button button-secondary"><?= e(t('design.load_preset')) ?></button>
                </div>
<?php endforeach; ?>
            </div>
        </section>

        <div class="design-workspace">
            <form id="design-form" method="post" action="<?= e(Url::admin('appearance')) ?>" class="stack design-form" data-design-form
                  data-check-url="<?= e(Url::admin('appearance', 'check')) ?>" data-preview-url="<?= e(Url::admin('appearance', 'preview')) ?>">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="character" value="<?= e($character) ?>">

                <div class="tabs" data-tabs>
                    <div class="tab-strip" data-tab-strip>
<?php foreach ($tabs as $index => $name): ?>
                        <a class="tab<?= $index === 0 ? ' tab-current' : '' ?>" href="#panel-<?= e($name) ?>" id="tab-<?= e($name) ?>" data-tab="<?= e($name) ?>"><?= e(t('appearance.tab.' . $name)) ?></a>
<?php endforeach; ?>
                    </div>
<?php foreach ($tabs as $name): ?>
                    <div class="tab-panel" id="panel-<?= e($name) ?>" data-panel="<?= e($name) ?>" aria-labelledby="tab-<?= e($name) ?>">
                        <?php include __DIR__ . '/tabs/' . $name . '.php'; ?>
                    </div>
<?php endforeach; ?>
                </div>
            </form>

            <div class="design-preview">
                <div class="preview-frame">
                    <?php /* PUBLISH IN THE BAR (D-058): the one part of a sticky column that
                             is always in view. Below a frame this tall, a button sits past the
                             bottom of the window and stays there however far the page is
                             scrolled. The buttons submit the form by name, not by containment. */ ?>
                    <?php /* TWO ROWS, NOT ONE WRAPPING ONE. Everything here — a label, three
                             widths, a zoom, Compare, what state the screen is in, and two
                             actions — does not fit across a column this wide, and left to
                             wrap it landed in a different arrangement at every width. So the
                             rows are declared: what this is and what to do with it, then the
                             tools for looking at it. */ ?>
                    <?php /* TWO ROWS, NOT ONE WRAPPING ONE. A label, three widths, a zoom,
                             Compare, what state the screen is in and two actions do not fit
                             across a column this wide, and left to wrap they landed in a
                             different arrangement at every width. So the rows are declared:
                             what this is and what to do with it, then the tools for looking
                             at it. */ ?>
                    <div class="preview-bar">
                        <div class="preview-bar-row">
                            <span class="preview-label"><?= e(t('design.preview.title')) ?></span>

                            <?php /* What the owner is looking at: their own unpublished work, or
                                     the site as it stands. It starts as "published", because on
                                     arrival the screen IS the site. */ ?>
                            <span class="preview-state" data-state role="status"
                                  data-published="<?= e(t('appearance.state.published')) ?>"
                                  data-unpublished="<?= e(t('appearance.state.unpublished')) ?>"
                                  data-problem="<?= e(t('appearance.state.problem')) ?>"><?= e(t('appearance.state.published')) ?></span>

                            <div class="preview-actions">
                                <button type="submit" form="design-preview-form" class="button button-quiet" data-preview-button><?= e(t('design.update_preview')) ?></button>
                                <a class="button button-quiet" href="<?= e(Url::admin('appearance')) ?>" data-revert hidden><?= e(t('appearance.revert')) ?></a>
<?php if ($character !== '' && $hasBlocks): ?>
                                <button type="submit" form="design-form" name="action" value="save" class="button button-secondary"><?= e(t('design.apply.design_only')) ?></button>
                                <button type="submit" form="design-form" name="action" value="save_composition" class="button"><?= e(t('design.apply.with_composition')) ?></button>
<?php else: ?>
                                <button type="submit" form="design-form" name="action" value="save" class="button"><?= e(t('appearance.publish')) ?></button>
<?php endif; ?>
                            </div>
                        </div>

                        <?php /* THE TOOLS (D-060). Three widths, a zoom, and Compare. Each is a
                                 button or a select with a visible resting state; none of them
                                 appears on hover. Without JavaScript they are not there at all,
                                 and the frame is what it always was — the column's width, at
                                 full size. */ ?>
                        <div class="preview-tools" data-preview-tools hidden>
                            <div class="viewports" role="group" aria-label="<?= e(t('appearance.width')) ?>">
<?php foreach (['desktop' => 1280, 'tablet' => 834, 'phone' => 390] as $name => $width): ?>
                                <button type="button" class="viewport" data-viewport="<?= e((string) $width) ?>" aria-pressed="<?= $name === 'desktop' ? 'true' : 'false' ?>"><?= e(t('appearance.width.' . $name)) ?></button>
<?php endforeach; ?>
                            </div>
                            <label class="zoom">
                                <span class="visually-hidden"><?= e(t('appearance.zoom')) ?></span>
                                <select data-zoom>
                                    <option value="fit"><?= e(t('appearance.zoom.fit')) ?></option>
                                    <option value="1">100%</option>
                                    <option value="0.75">75%</option>
                                    <option value="0.5">50%</option>
                                </select>
                            </label>
                            <?php /* Held, not toggled: a comparison you have to keep holding is
                                     one you cannot walk away from and mistake for the site. */ ?>
                            <button type="button" class="viewport viewport-compare" data-compare aria-pressed="false" title="<?= e(t('appearance.compare_hint')) ?>"><?= e(t('appearance.compare')) ?></button>
                        </div>
                    </div>
                    <?php /* THE ZOOM SCALES THE STAGE, NEVER THE FRAME'S WIDTH. A page judged
                             at 1280 has to lay itself out at 1280; shrinking the frame instead
                             would hand it a narrower window and it would answer with the phone
                             layout, which is a different question entirely. */ ?>
                    <div class="preview-stage" data-stage>
                        <iframe name="design-preview" src="<?= e($previewUrl) ?>" title="<?= e(t('design.preview')) ?>" data-design-preview></iframe>
                    </div>
                </div>
<?php if ($character !== ''): ?>
                <p class="preview-note"><?= e(t('design.preview.composition_note', ['character' => t('design.preset.' . $character)])) ?></p>
<?php else: ?>
                <p class="preview-note"><?= e(t('design.preview.note')) ?></p>
<?php endif; ?>
<?php if ($character !== '' && $hasBlocks): ?>
                <div class="apply-notes">
                    <p class="hint"><strong><?= e(t('design.apply.design_only')) ?></strong> — <?= e(t('design.apply.design_only_hint')) ?></p>
                    <p class="hint"><strong><?= e(t('design.apply.with_composition')) ?></strong> — <?= e(t('design.apply.with_composition_hint')) ?></p>
                </div>
<?php elseif ($character !== ''): ?>
                <p class="hint"><?= e(t('design.apply.no_blocks')) ?></p>
<?php endif; ?>
            </div>
        </div>

        <?php /* Without JavaScript this form sends the current values to the preview frame. */ ?>
        <form id="design-preview-form" method="get" action="<?= e(Url::admin('appearance', 'preview')) ?>" target="design-preview" class="visually-hidden"></form>
        <script src="<?= e(Url::versioned('assets/appearance.js')) ?>" defer></script>
        <?php /* The link field (page or address) is admin.js's, as everywhere else. */ ?>
        <script src="<?= e(Url::versioned('assets/appearance-tabs.js')) ?>" defer></script>
        <script src="<?= e(Url::versioned('assets/appearance-stage.js')) ?>" defer></script>
