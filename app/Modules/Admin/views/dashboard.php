<?php

use App\Support\Url;

/**
 * Where the site stands, and what to do next. Provided by View::render().
 *
 * @var string $title
 * @var int $published
 * @var int $drafts
 * @var int $pictures
 * @var int $menus
 * @var string $character the character the site was last given
 * @var int|null $homeId the home page's id, null before there is one
 * @var bool $maintenance
 */
?>
        <div class="page-header">
            <h1><?= e($title) ?></h1>
        </div>
        <p class="page-subtitle"><?= e(t('admin.dashboard.intro')) ?></p>
<?php if ($maintenance): ?>
        <p class="notice notice-warning"><?= e(t('admin.dashboard.maintenance')) ?>
            <a href="<?= e(Url::admin('settings')) ?>"><?= e(t('admin.dashboard.maintenance_link')) ?></a></p>
<?php endif; ?>

        <?php /* Each figure is a way in: the card is the link to the screen it counts. */ ?>
        <ul class="stat-grid" role="list">
            <li><a class="stat" href="<?= e(Url::admin('pages')) ?>">
                <span class="stat-label"><?= e(t('admin.nav.pages')) ?></span>
                <span class="stat-value"><?= e((string) $published) ?></span>
                <span class="stat-note"><?= e(t('admin.dashboard.published', ['drafts' => $drafts])) ?></span>
            </a></li>
            <li><a class="stat" href="<?= e(Url::admin('media')) ?>">
                <span class="stat-label"><?= e(t('admin.nav.media')) ?></span>
                <span class="stat-value"><?= e((string) $pictures) ?></span>
                <span class="stat-note"><?= e(t('admin.dashboard.pictures')) ?></span>
            </a></li>
            <li><a class="stat" href="<?= e(Url::admin('design')) ?>">
                <span class="stat-label"><?= e(t('admin.nav.design')) ?></span>
                <span class="stat-value stat-word"><?= e(t('design.preset.' . $character)) ?></span>
                <span class="stat-note"><?= e(t('admin.dashboard.character')) ?></span>
            </a></li>
            <li><a class="stat" href="<?= e(Url::admin('menus')) ?>">
                <span class="stat-label"><?= e(t('admin.nav.menus')) ?></span>
                <span class="stat-value"><?= e((string) $menus) ?></span>
                <span class="stat-note"><?= e(t('admin.dashboard.menus')) ?></span>
            </a></li>
        </ul>

        <section class="panel" aria-labelledby="next-heading">
            <h2 id="next-heading"><?= e(t('admin.dashboard.next')) ?></h2>
            <div class="form-actions">
<?php if ($homeId !== null): ?>
                <a class="button" href="<?= e(Url::admin('pages', $homeId)) ?>"><?= e(t('admin.dashboard.edit_home')) ?></a>
<?php endif; ?>
                <a class="button<?= $homeId !== null ? ' button-secondary' : '' ?>" href="<?= e(Url::admin('pages', 'new')) ?>"><?= e(t('pages.new')) ?></a>
                <a class="button button-secondary" href="<?= e(Url::admin('design')) ?>"><?= e(t('admin.dashboard.change_design')) ?></a>
                <a class="button button-secondary" href="<?= e(Url::admin('media')) ?>"><?= e(t('admin.dashboard.add_pictures')) ?></a>
            </div>
        </section>
