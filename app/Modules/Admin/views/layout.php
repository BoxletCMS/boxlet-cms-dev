<?php

use App\Support\Url;

/**
 * Provided by View::render().
 *
 * @var string $locale
 * @var string $title
 * @var string $content rendered HTML of the page template
 * @var string $siteName
 * @var string $csrf
 */
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
</head>
<body class="admin">
    <header class="admin-bar">
        <span class="admin-brand"><?= e($siteName !== '' ? $siteName : t('admin.brand')) ?></span>
        <nav class="admin-nav" aria-label="<?= e(t('admin.nav.label')) ?>">
            <a href="<?= e(Url::admin()) ?>" aria-current="page"><?= e(t('admin.nav.dashboard')) ?></a>
        </nav>
        <form class="admin-logout" method="post" action="<?= e(Url::admin('logout')) ?>">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit" class="button button-quiet"><?= e(t('admin.logout')) ?></button>
        </form>
    </header>
    <main class="admin-main">
<?= $content ?>
    </main>
</body>
</html>
