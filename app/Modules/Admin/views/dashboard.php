<?php

use App\Support\Url;

/**
 * Provided by View::render().
 *
 * @var string $title
 */
?>
        <div class="page-header">
            <h1><?= e($title) ?></h1>
        </div>
        <div class="panel stack">
            <p><?= e(t('admin.dashboard.intro')) ?></p>
            <div class="row-actions">
                <a class="button" href="<?= e(Url::admin('pages')) ?>"><?= e(t('admin.nav.pages')) ?></a>
                <a class="button button-secondary" href="<?= e(Url::admin('design')) ?>"><?= e(t('admin.nav.design')) ?></a>
            </div>
        </div>
