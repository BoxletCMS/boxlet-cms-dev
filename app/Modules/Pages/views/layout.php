<?php use App\Support\Url; ?>
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
<?php foreach ($locales as $code => $info): ?>
            <a href="<?= e(Url::page($code, 'hello')) ?>" hreflang="<?= e($code) ?>"<?= $code === $locale ? ' aria-current="true"' : '' ?>><?= e($info['label']) ?></a>
<?php endforeach; ?>
        </nav>
    </footer>
</body>
</html>
