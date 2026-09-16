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
 * @var string $nav current section: dashboard, pages or design
 * @var list<string> $styles extra stylesheets under public/assets
 * @var list<string> $scripts extra scripts under public/assets, in load order
 * @var bool $wide whether this screen wants the wide column
 * @var bool $bare whether this screen fills the window instead of the reading column
 * @var string|null $flash one-time message from the previous request
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
    <link rel="stylesheet" href="<?= e(Url::versioned('assets/admin-ui.css')) ?>">
    <link rel="stylesheet" href="<?= e(Url::versioned('assets/admin-forms.css')) ?>">
<?php foreach ($styles as $style): ?>
    <link rel="stylesheet" href="<?= e(Url::versioned('assets/' . $style)) ?>">
<?php endforeach; ?>
    <script src="<?= e(Url::versioned('assets/admin.js')) ?>" defer></script>
<?php foreach ($scripts as $script): ?>
    <script src="<?= e(Url::versioned('assets/' . $script)) ?>" defer></script>
<?php endforeach; ?>
</head>
<body class="admin">
    <a class="skip-link" href="#admin-content"><?= e(t('admin.skip')) ?></a>
    <header class="admin-bar">
        <div class="admin-bar-inner">
            <span class="admin-brand"><?= e($siteName !== '' ? $siteName : t('admin.brand')) ?></span>
            <nav class="admin-nav" aria-label="<?= e(t('admin.nav.label')) ?>">
                <a href="<?= e(Url::admin()) ?>"<?= $current('dashboard') ?>><?= e(t('admin.nav.dashboard')) ?></a>
                <a href="<?= e(Url::admin('pages')) ?>"<?= $current('pages') ?>><?= e(t('admin.nav.pages')) ?></a>
                <a href="<?= e(Url::admin('design')) ?>"<?= $current('design') ?>><?= e(t('admin.nav.design')) ?></a>
            </nav>
            <form class="admin-logout" method="post" action="<?= e(Url::admin('logout')) ?>">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button type="submit" class="button button-ghost"><?= e(t('admin.logout')) ?></button>
            </form>
        </div>
    </header>
    <main class="admin-main<?= $wide ? ' admin-main-wide' : '' ?><?= $bare ? ' admin-main-bare' : '' ?>" id="admin-content">
<?php if ($flash !== null): ?>
        <p class="notice notice-success" role="status"><?= e($flash) ?></p>
<?php endif; ?>
<?= $content ?>
    </main>
</body>
</html>
