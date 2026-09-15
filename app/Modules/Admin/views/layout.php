<?php

use App\Support\Url;

/**
 * Provided by AdminView::render().
 *
 * @var string $locale
 * @var string $title
 * @var string $content rendered HTML of the page template
 * @var string $siteName
 * @var string $nav current section: dashboard or pages
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
    <link rel="stylesheet" href="<?= e(Url::asset('cache/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= e(Url::asset('assets/admin.css')) ?>">
    <link rel="stylesheet" href="<?= e(Url::asset('assets/admin-pages.css')) ?>">
    <script src="<?= e(Url::asset('assets/admin.js')) ?>" defer></script>
</head>
<body class="admin">
    <header class="admin-bar">
        <span class="admin-brand"><?= e($siteName !== '' ? $siteName : t('admin.brand')) ?></span>
        <nav class="admin-nav" aria-label="<?= e(t('admin.nav.label')) ?>">
            <a href="<?= e(Url::admin()) ?>"<?= $current('dashboard') ?>><?= e(t('admin.nav.dashboard')) ?></a>
            <a href="<?= e(Url::admin('pages')) ?>"<?= $current('pages') ?>><?= e(t('admin.nav.pages')) ?></a>
        </nav>
        <form class="admin-logout" method="post" action="<?= e(Url::admin('logout')) ?>">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit" class="button button-quiet"><?= e(t('admin.logout')) ?></button>
        </form>
    </header>
    <main class="admin-main">
<?php if ($flash !== null): ?>
        <p class="notice" role="status"><?= e($flash) ?></p>
<?php endif; ?>
<?= $content ?>
    </main>
</body>
</html>
