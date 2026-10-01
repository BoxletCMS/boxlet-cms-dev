<?php

use App\Modules\Design\Characters;
use App\Modules\Design\Presets;
use App\Support\Url;

/**
 * The rail (PLAN.md D-059, D-061, D-152): the characters, the designs the owner keeps, and a
 * design brought in as a file. Split from appearance.php, which had reached the 500-line
 * limit, along the line its own stylesheet (admin-appearance-rail.css) already drew.
 * Required inside #design-form, in appearance.php's scope.
 *
 * @var Closure(array<string, string>, string, string, string, string, string, string=): string $card
 * @var string $activeCharacter
 * @var list<array{id: int, name: string, character: string, decisions: array<string, string>, look: array<string, string>}> $library
 * @var Closure(string): string $error
 */
?>
                <div class="appearance-rail" id="appearance-rail">
                    <h2 class="rail-heading"><?= e(t('appearance.characters')) ?> <span><?= e(t('appearance.characters_hint')) ?></span></h2>
<?php foreach (Presets::names() as $preset): ?>
                    <?= $card(
                        Presets::get($preset),
                        Characters::look($preset)['header_arrangement'],
                        Characters::label($preset),
                        $preset === $activeCharacter ? t('design.preset.current') : '',
                        'preset-' . $preset,
                        // An imported character can be removed; every one can be taken away as a
                        // file (D-152).
                        '<span class="rail-card-tools">'
                            . '<a class="icon-button" href="' . e(Url::admin('appearance', 'export', 'character', $preset)) . '" title="' . e(t('appearance.export_one', ['name' => Characters::label($preset)])) . '">'
                            . icon('download') . '<span class="visually-hidden">' . e(t('appearance.export_one', ['name' => Characters::label($preset)])) . '</span></a>'
                            . (Characters::source($preset) === 'imported'
                                ? '<button type="submit" form="design-form" name="action" value="character:delete:' . e($preset) . '" class="icon-button"'
                                    . ' data-confirm="' . e(t('appearance.character_delete_confirm', ['name' => Characters::label($preset)])) . '" title="' . e(t('appearance.character_delete', ['name' => Characters::label($preset)])) . '">'
                                    . icon('trash-2') . '<span class="visually-hidden">' . e(t('appearance.character_delete', ['name' => Characters::label($preset)])) . '</span></button>'
                                : '')
                            . '</span>'
                        . '<button type="submit" form="design-form" name="action" value="preset:' . e($preset) . '" class="rail-use">'
                            . '<span class="visually-hidden">' . e(t('design.load_preset')) . ': ' . e(Characters::label($preset)) . '</span></button>',
                        // A character an owner added says so.
                        Characters::source($preset) !== 'core' ? t('appearance.custom') : '',
                    ) ?>
<?php endforeach; ?>

                    <h2 class="rail-heading"><?= e(t('appearance.library')) ?>
                        <span><?= e($library === [] ? t('appearance.library.empty_rail') : t('appearance.library_count', ['count' => count($library)])) ?></span></h2>
<?php foreach ($library as $saved): ?>
                    <?= $card(
                        $saved['decisions'],
                        // Its own choice, else its character's, else nothing to say.
                        ($saved['look']['header_arrangement'] ?? '') !== '' ? $saved['look']['header_arrangement'] : (Characters::exists($saved['character']) ? Characters::look($saved['character'])['header_arrangement'] : ''),
                        $saved['name'],
                        '',
                        'saved-' . $saved['id'],
                        '<span class="rail-card-tools">'
                            . '<a class="icon-button" href="' . e(Url::admin('appearance', 'export', 'library', $saved['id'])) . '" title="' . e(t('appearance.export_one', ['name' => $saved['name']])) . '">'
                            . icon('download') . '<span class="visually-hidden">' . e(t('appearance.export_one', ['name' => $saved['name']])) . '</span></a>'
                            . '<button type="submit" form="design-form" name="action" value="library:save:' . $saved['id'] . '" class="icon-button" title="' . e(t('appearance.library.overwrite', ['name' => $saved['name']])) . '">'
                            . icon('replace') . '<span class="visually-hidden">' . e(t('appearance.library.overwrite', ['name' => $saved['name']])) . '</span></button>'
                            . '<button type="submit" form="design-form" name="action" value="library:delete:' . $saved['id'] . '" class="icon-button" title="' . e(t('appearance.library.delete_one', ['name' => $saved['name']])) . '">'
                            . icon('trash-2') . '<span class="visually-hidden">' . e(t('appearance.library.delete_one', ['name' => $saved['name']])) . '</span></button>'
                            . '</span>'
                            . '<button type="submit" form="design-form" name="action" value="library:use:' . $saved['id'] . '" class="rail-use">'
                            . '<span class="visually-hidden">' . e(t('appearance.library.use')) . ': ' . e($saved['name']) . '</span></button>',
                    ) ?>
<?php endforeach; ?>

                    <div class="rail-keep">
                        <?php /* A .field, so it is the admin's own input rather than the
                                 browser's (D-078). It carried only a width, so what was
                                 drawn was a 2px inset border on rgb(59, 59, 59) with a
                                 content-box width of 100% — which is how it came to touch
                                 the edge of the column. */ ?>
                        <div class="field">
                            <label class="visually-hidden" for="library_name"><?= e(t('appearance.library.name')) ?></label>
                            <input type="text" id="library_name" name="library_name" maxlength="80" value="" autocomplete="off"
                                   placeholder="<?= e(t('appearance.library.name')) ?>">
                            <?= $error('library_name') ?>
                        </div>
                        <button type="submit" form="design-form" name="action" value="library:save" class="button button-secondary"><?= e(t('appearance.library.save')) ?></button>
                    </div>

                    <?php /* A DESIGN AS A FILE, IN (D-152). The file input and its button stand here
                             but post the import form outside this one, by their form attribute:
                             a form inside a form is not a form. Without a script, choose and press. */ ?>
                    <div class="rail-import">
                        <?php /* The browser's own file control speaks the browser's language and
                                 cannot be styled, so it is hidden and a label in the admin's words
                                 opens it, as for the SVG logo (D-142). Choosing a file sends it
                                 (file-sends.js); without a script, the button beside it does. */ ?>
                        <input type="file" id="design-file" name="design" accept="application/json,.json" form="design-import" class="visually-hidden" data-file-sends>
                        <label for="design-file" class="button button-secondary"><?= icon('cloud-upload') ?> <?= e(t('appearance.import')) ?></label>
                        <button type="submit" form="design-import" class="button no-js-only"><?= e(t('appearance.import_send')) ?></button>
                        <span class="hint"><?= e(t('appearance.import_label')) ?></span>
                    </div>
                </div>
