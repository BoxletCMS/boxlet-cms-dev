<?php

use App\Modules\Design\Characters;
use App\Modules\Design\Presets;
use App\Support\Url;

/**
 * A design as a file, in (PLAN.md D-152), and what the screen must say about the characters
 * it cannot use (D-155, D-156). Required by appearance.php above #design-form, in its scope.
 *
 * THE TWO FORMS HERE ARE EMPTY ON PURPOSE. Their controls stand elsewhere — the file input in
 * the rail, the answers in the panel — and post them by their form attribute, because those
 * places are inside #design-form and a form inside a form is not one. Without a script every
 * one of them is a plain post.
 *
 * @var array{set: array<string, mixed>, warnings: list<string>}|null $import
 * @var list<string> $importErrors
 * @var list<array{file: string, reason: string}> $skipped
 * @var string|null $missingCharacter
 * @var string $csrf
 * @var Closure(array<string, string>, string, string, string, string, string, string=): string $card
 */
?>
        <form id="design-import" method="post" action="<?= e(Url::admin('appearance', 'import')) ?>" enctype="multipart/form-data" hidden>
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        </form>
        <form id="design-import-add" method="post" action="<?= e(Url::admin('appearance', 'import', 'add')) ?>" hidden>
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        </form>
<?php if ($missingCharacter !== null): ?>
            <p class="notice appearance-notice" role="status"><?= e(t('appearance.character_missing', ['name' => $missingCharacter, 'default' => Characters::label(Presets::DEFAULT)])) ?></p>
<?php endif; ?>
<?php if ($skipped !== []): ?>
            <div class="notice appearance-notice" role="status">
                <p><?= e(t('appearance.skipped')) ?></p>
                <ul class="import-list">
<?php foreach ($skipped as $entry): ?>
                    <li><strong><?= e($entry['file']) ?></strong> — <?= e($entry['reason']) ?></li>
<?php endforeach; ?>
                </ul>
            </div>
<?php endif; ?>
<?php if ($importErrors !== []): ?>
            <div class="notice appearance-notice notice-error" role="alert">
                <p><?= e(t('appearance.import_refused', ['reason' => ''])) ?></p>
                <ul class="import-list">
<?php foreach ($importErrors as $reason): ?>
                    <li><?= e($reason) ?></li>
<?php endforeach; ?>
                </ul>
            </div>
<?php endif; ?>
<?php if ($import !== null):
    $set = $import['set'];
    $name = (string) ($set['name']['en'] ?? reset($set['name']));
    ?>
            <?php /* Like the question Publish asks (appearance-confirm): what it is, then each
                     answer with what it does written beside it. */ ?>
            <div class="appearance-confirm import-confirm" role="alert">
                <p class="confirm-question"><?= e(t('appearance.import_question', ['name' => $name])) ?></p>
                <?= $card($set['decisions'], (string) ($set['look']['header_arrangement'] ?? ''), $name, '', 'import', '') ?>
<?php if ($import['warnings'] !== []): ?>
                <p class="hint"><?= e(t('appearance.import_warnings')) ?></p>
                <ul class="import-list hint">
<?php foreach ($import['warnings'] as $warning): ?>
                    <li><?= e($warning) ?></li>
<?php endforeach; ?>
                </ul>
<?php endif; ?>
<?php if ($set['composition'] === null): ?>
                <p class="hint"><?= e(t('appearance.import_design_only')) ?></p>
<?php endif; ?>
                <div class="confirm-options">
<?php if ($set['composition'] !== null): ?>
                    <span>
                        <button type="submit" form="design-import-add" class="button"><?= e(t('appearance.import_add')) ?></button>
                        <span class="hint"><?= e(t('appearance.import_add_hint')) ?></span>
                    </span>
<?php endif; ?>
                    <span>
                        <button type="submit" form="design-form" name="action" value="import:load" class="button button-secondary"><?= e(t('appearance.import_load')) ?></button>
                        <span class="hint"><?= e(t('appearance.import_load_hint')) ?></span>
                    </span>
                    <span>
                        <a class="button button-quiet" href="<?= e(Url::admin('appearance')) ?>"><?= e(t('appearance.import_cancel')) ?></a>
                    </span>
                </div>
            </div>
<?php endif; ?>
