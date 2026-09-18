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
 * @var string $headerHtml the site header, already rendered, or '' when there is none (5c)
 * @var string $footerHtml the site footer, already rendered, or '' when there is none.
 *                         It carries the language switcher: the site has exactly one footer
 *                         and this layout must not draw a second (D-028).
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
<?php /* THE SHEET (PLAN.md D-031). A boxed page needs something to be a page: <body> holds
         the colour around it and this element is the page itself. Without a wrapper there
         was nothing to inset, because body carried both the background and the content.
         When the page is not boxed the frame token is zero, so this fills the window and
         the colour around it is never seen — which is also why no text can land on it. */ ?>
    <div class="page">
<?= $headerHtml ?>
    <main>
<?= $content ?>
    </main>
<?= $footerHtml ?>
    </div>
<?php /* NO SECOND FOOTER HERE. The site has exactly one and the chrome footer is it — it
         carries the language switcher through the same partial this layout used to include.
         Rendering the switcher here as well would put two of them on every translated page,
         which is what "one footer" was decided to prevent (D-028). */ ?>
</body>
</html>
