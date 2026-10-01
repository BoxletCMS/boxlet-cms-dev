<?php

use App\Support\Url;

/**
 * The Appearance screen's bar (PLAN.md D-059, D-068): what the screen is, how to look at the
 * picture, and what to do with what is on it. Required by appearance.php, in its scope.
 *
 * @var string $title
 */
?>
            <?php /* THE BAR. Everything that acts on the whole screen: what it is on the
                     left, how to look at it and what to do with it on the right. */ ?>
            <div class="appearance-bar">
                <span class="appearance-title"><?= icon('palette') ?><strong><?= e($title) ?></strong>
                    <span class="appearance-subhead"><?= e(t('appearance.subhead')) ?></span></span>

                <div class="preview-tools" data-preview-tools hidden>
                    <div class="viewports" role="group" aria-label="<?= e(t('appearance.width')) ?>">
<?php foreach (['desktop' => 1280, 'tablet' => 834, 'phone' => 390] as $name => $width): ?>
                        <button type="button" class="viewport" data-viewport="<?= e((string) $width) ?>" aria-pressed="<?= $name === 'desktop' ? 'true' : 'false' ?>" title="<?= e(t('appearance.width.' . $name)) ?>"><?= e(t('appearance.width.' . $name)) ?></button>
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
                    <?php /* Held, not toggled: a comparison you have to keep holding is one
                             you cannot walk away from and mistake for the site. */ ?>
                    <button type="button" class="viewport viewport-compare" data-compare aria-pressed="false" title="<?= e(t('appearance.compare_hint')) ?>"><?= e(t('appearance.compare')) ?></button>
                </div>

                <span class="preview-state" data-state role="status"
                      data-published="<?= e(t('appearance.state.published')) ?>"
                      data-unpublished="<?= e(t('appearance.state.unpublished')) ?>"
                      data-problem="<?= e(t('appearance.state.problem')) ?>"><?= e(t('appearance.state.published')) ?></span>

                <?php /* ONE BUTTON. Applying a character to a site that has blocks can rewrite
                         every section, so that needs two explicit answers — but the question
                         belongs at the moment of publishing, not permanently in the bar
                         (D-068). */ ?>
                <div class="preview-actions">
                    <button type="submit" form="design-preview-form" class="button button-quiet" data-preview-button><?= e(t('design.update_preview')) ?></button>
                    <a class="button button-quiet" href="<?= e(Url::admin('appearance')) ?>" data-revert hidden><?= e(t('appearance.revert')) ?></a>
                    <?php /* The screen's design as a file (D-152): what is on it, published or not. */ ?>
                    <button type="submit" form="design-form" name="action" value="export" class="button button-quiet"><?= icon('download') ?> <?= e(t('appearance.export')) ?></button>
                    <button type="submit" form="design-form" name="action" value="save" class="button"><?= e(t('appearance.publish')) ?></button>
                </div>
            </div>
