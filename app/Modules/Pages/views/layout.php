<?php

use App\Support\Url;

/**
 * Provided by View::render().
 *
 * @var string $locale
 * @var string $title
 * @var string $content rendered HTML of the page template
 * @var array<int, array<string, mixed>> $locales enabled locales, with code and label
 * @var string|null $canonical absolute canonical URL; null on error pages
 * @var string $description meta description; empty when the page gives none (D-004)
 * @var array{url: string, type: string}|null $icon the site's tab icon (D-028)
 * @var string|null $shareImage absolute URL of the default sharing picture (D-028)
 */
?>
<!doctype html>
<html lang="<?= e($locale) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
<?php /* Omitted rather than emitted empty: a description repeating the title is worse
         than none, which is why this field does not fall back to it (D-004). */ ?>
<?php if ($description !== ''): ?>
    <meta name="description" content="<?= e($description) ?>">
<?php endif; ?>
<?php if ($canonical !== null): ?>
    <link rel="canonical" href="<?= e($canonical) ?>">
<?php endif; ?>
<?php /* One 200×200 file for both: a browser downscales it for the tab, and iOS takes the
         same picture for a home-screen icon. sizes="any" says it is not a fixed 16 or 32
         rather than claiming a size it is not (D-028). */ ?>
<?php if ($icon !== null): ?>
    <link rel="icon" href="<?= e($icon['url']) ?>" type="<?= e($icon['type']) ?>" sizes="any">
    <link rel="apple-touch-icon" href="<?= e($icon['url']) ?>">
<?php endif; ?>
<?php if ($shareImage !== null): ?>
    <meta property="og:image" content="<?= e($shareImage) ?>">
<?php endif; ?>
    <link rel="stylesheet" href="<?= e(Url::stylesheet()) ?>">
    <link rel="stylesheet" href="<?= e(Url::versioned('assets/site.css')) ?>">
    <link rel="stylesheet" href="<?= e(Url::versioned('assets/sections.css')) ?>">
</head>
<body>
    <main>
<?= $content ?>
    </main>
<?php /* ONE footer for the site (D-028, 5c). The switcher lives in its own partial so the
         owner's footer can include it instead of carrying a second copy; until that footer
         exists this is still the only <footer> on the page, and it draws nothing at all
         when a single locale is enabled. */ ?>
<?php if (count($locales) > 1): ?>
    <footer class="container">
<?php require __DIR__ . '/partials/locale-switcher.php'; ?>
    </footer>
<?php endif; ?>
</body>
</html>
