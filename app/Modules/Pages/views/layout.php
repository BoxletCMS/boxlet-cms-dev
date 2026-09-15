<?php

use App\Support\Url;

/**
 * Provided by View::render().
 *
 * @var string $locale
 * @var string $title
 * @var string $content rendered HTML of the page template
 * @var list<array{code: string, label: string}> $locales enabled locales
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
    <main class="container">
<?= $content ?>
    </main>
    <footer class="container">
        <nav class="locale-switcher">
<?php foreach ($locales as $option): ?>
            <a href="<?= e(Url::page($option['code'], 'hello')) ?>" hreflang="<?= e($option['code']) ?>"<?= $option['code'] === $locale ? ' aria-current="true"' : '' ?>><?= e($option['label']) ?></a>
<?php endforeach; ?>
        </nav>
    </footer>
</body>
</html>
