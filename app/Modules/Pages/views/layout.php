<?php

use App\Support\Url;

/**
 * Provided by View::render().
 *
 * @var string $locale
 * @var string $title
 * @var string $content rendered HTML of the page template
 * @var array<int, array<string, mixed>> $locales enabled locales, with code and label
 */
?>
<!doctype html>
<html lang="<?= e($locale) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <link rel="stylesheet" href="<?= e(Url::asset('cache/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= e(Url::asset('assets/site.css')) ?>">
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
