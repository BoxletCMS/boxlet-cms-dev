<?php

use App\Support\Url;

/**
 * Provided by View::render().
 *
 * @var string $title
 * @var bool $maintenanceOn whether the site is hidden from visitors (D-021)
 * @var string $csrf
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

        <?php /* Maintenance mode (D-021). The state is said in words before the button,
                 because "Turn on maintenance mode" alone does not tell the owner which
                 way round the site currently is. */ ?>
        <div class="panel stack">
            <h2><?= e(t('maintenance.title')) ?></h2>
            <p class="<?= $maintenanceOn ? 'notice notice-warning' : 'hint' ?>"<?= $maintenanceOn ? ' role="status"' : '' ?>>
                <?= e($maintenanceOn ? t('maintenance.on_now') : t('maintenance.off_now')) ?>
            </p>
            <form method="post" action="<?= e(Url::admin('maintenance')) ?>">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="state" value="<?= $maintenanceOn ? 'off' : 'on' ?>">
                <button type="submit" class="button<?= $maintenanceOn ? '' : ' button-secondary' ?>">
                    <?= e($maintenanceOn ? t('maintenance.turn_off') : t('maintenance.turn_on')) ?>
                </button>
            </form>
        </div>
