<?php

use App\Support\Url;

/**
 * The admin shell. It links only the admin's own stylesheets: the site's compiled
 * tokens never reach the admin, so the tool stays a stable reference whatever the site
 * is set to (SPEC §5.4). The one place the site's design appears is the Design
 * preview, which is an iframe with its own document.
 *
 * Provided by AdminView::render().
 *
 * @var string $locale
 * @var string $title
 * @var string $content rendered HTML of the page template
 * @var string $siteName
 * @var string $nav current section: dashboard, pages, media, design, menus or settings
 * @var list<string> $styles extra stylesheets under public/assets
 * @var list<string> $scripts extra scripts under public/assets, in load order
 * @var bool $wide whether this screen wants the wide column
 * @var bool $bare whether this screen fills the window instead of the reading column
 * @var string|null $flash one-time message from the previous request
 * @var string $flashKind 'success' or 'warning'; a refusal must not be coloured as a win
 * @var string $csrf
 */
$current = static fn (string $section): string => $nav === $section ? ' aria-current="page"' : '';
?>
<!doctype html>
<html lang="<?= e($locale) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e($title) ?></title>
    <link rel="stylesheet" href="<?= e(Url::versioned('assets/admin.css')) ?>">
    <link rel="stylesheet" href="<?= e(Url::versioned('assets/admin-shell.css')) ?>">
    <link rel="stylesheet" href="<?= e(Url::versioned('assets/admin-ui.css')) ?>">
    <link rel="stylesheet" href="<?= e(Url::versioned('assets/admin-forms.css')) ?>">
    <link rel="stylesheet" href="<?= e(Url::versioned('assets/admin-tables.css')) ?>">
<?php foreach ($styles as $style): ?>
    <link rel="stylesheet" href="<?= e(Url::versioned('assets/' . $style)) ?>">
<?php endforeach; ?>
    <script src="<?= e(Url::versioned('assets/admin.js')) ?>" defer></script>
    <script src="<?= e(Url::versioned('assets/admin-nav.js')) ?>" defer></script>
<?php foreach ($scripts as $script): ?>
    <script src="<?= e(Url::versioned('assets/' . $script)) ?>" defer></script>
<?php endforeach; ?>
</head>
<body class="admin">
    <a class="skip-link" href="#admin-content"><?= e(t('admin.skip')) ?></a>
    <header class="admin-bar" data-admin-bar>
        <div class="admin-bar-inner">
            <span class="admin-brand"><?= e($siteName !== '' ? $siteName : t('admin.brand')) ?></span>
            <?php /* The phone's menu button, born hidden: admin-nav.js shows it and folds the
                     navigation under it. Without a script the navigation simply wraps. */ ?>
            <button type="button" class="admin-bar-icon admin-nav-toggle" aria-expanded="false" aria-controls="admin-nav" hidden data-admin-nav-toggle>
                <?= icon('menu') ?><span class="visually-hidden"><?= e(t('admin.nav.open')) ?></span>
            </button>
            <nav class="admin-nav" id="admin-nav" aria-label="<?= e(t('admin.nav.label')) ?>">
                <a href="<?= e(Url::admin()) ?>"<?= $current('dashboard') ?>><?= e(t('admin.nav.dashboard')) ?></a>
                <a href="<?= e(Url::admin('pages')) ?>"<?= $current('pages') ?>><?= e(t('admin.nav.pages')) ?></a>
                <a href="<?= e(Url::admin('media')) ?>"<?= $current('media') ?>><?= e(t('admin.nav.media')) ?></a>
                <?php /* The site's look and its header and footer are one subject, so they are
                         one entry with two screens under it. A <details>, so it opens and
                         closes without a script; admin-nav.js only closes it on a click
                         elsewhere. */ ?>
                <details class="admin-nav-group"<?= in_array($nav, ['design', 'chrome'], true) ? ' data-current' : '' ?>>
                    <summary><?= e(t('admin.nav.design')) ?><?= icon('chevron-down') ?></summary>
                    <div class="admin-nav-sub">
                        <a href="<?= e(Url::admin('design')) ?>"<?= $current('design') ?>><?= e(t('admin.nav.design_style')) ?></a>
                        <a href="<?= e(Url::admin('chrome')) ?>"<?= $current('chrome') ?>><?= e(t('admin.nav.chrome')) ?></a>
                    </div>
                </details>
                <a href="<?= e(Url::admin('menus')) ?>"<?= $current('menus') ?>><?= e(t('admin.nav.menus')) ?></a>
            </nav>
            <div class="admin-bar-end">
                <?php /* Icons alone, each named for a screen reader and on hover. */ ?>
                <a class="admin-bar-icon" href="<?= e(Url::admin('settings')) ?>" title="<?= e(t('admin.nav.settings')) ?>"<?= $current('settings') ?>>
                    <?= icon('settings') ?><span class="visually-hidden"><?= e(t('admin.nav.settings')) ?></span>
                </a>
                <?php /* The site's home in a new tab: leaving the admin mid-edit would lose
                         whatever is unsaved. */ ?>
                <a class="admin-bar-icon" href="<?= e(Url::asset('')) ?>" target="_blank" rel="noopener" title="<?= e(t('admin.view_site')) ?>">
                    <?= icon('external-link') ?><span class="visually-hidden"><?= e(t('admin.view_site')) ?></span>
                </a>
                <form class="admin-logout" method="post" action="<?= e(Url::admin('logout')) ?>">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <button type="submit" class="admin-bar-icon" title="<?= e(t('admin.logout')) ?>">
                        <?= icon('log-out') ?><span class="visually-hidden"><?= e(t('admin.logout')) ?></span>
                    </button>
                </form>
            </div>
        </div>
    </header>
    <main class="admin-main<?= $wide ? ' admin-main-wide' : '' ?><?= $bare ? ' admin-main-bare' : '' ?>" id="admin-content">
<?php if ($flash !== null): ?>
        <p class="notice notice-<?= e($flashKind) ?>" role="status"><?= e($flash) ?></p>
<?php endif; ?>
<?= $content ?>
    </main>
</body>
</html>
