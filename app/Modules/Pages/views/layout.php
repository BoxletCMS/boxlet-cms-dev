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
    <link rel="stylesheet" href="<?= e(Url::stylesheet()) ?>">
    <link rel="stylesheet" href="<?= e(Url::versioned('assets/site.css')) ?>">
    <link rel="stylesheet" href="<?= e(Url::versioned('assets/sections.css')) ?>">
</head>
<body>
    <main>
<?= $content ?>
    </main>
<?php if (count($locales) > 1): ?>
    <footer class="container">
        <nav class="locale-switcher">
<?php foreach ($locales as $option): ?>
            <a href="<?= e(Url::page((string) $option['code'])) ?>" hreflang="<?= e($option['code']) ?>" lang="<?= e($option['code']) ?>"<?= $option['code'] === $locale ? ' aria-current="true"' : '' ?>><?= e($option['label']) ?></a>
<?php endforeach; ?>
        </nav>
    </footer>
<?php endif; ?>
</body>
</html>
