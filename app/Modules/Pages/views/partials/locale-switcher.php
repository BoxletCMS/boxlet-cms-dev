<?php

use App\Support\Url;

/**
 * The language switcher (D-028, 5c).
 *
 * Extracted from layout.php rather than copied into the footer: the site gets exactly one
 * footer, and when the owner's own footer arrives it includes this instead of growing a
 * second copy of the same markup. Two footers on one page, or two switchers, would be the
 * kind of duplication that drifts apart a slice later.
 *
 * Renders nothing at all when only one locale is enabled, which is the behaviour it has
 * always had — a switcher offering one choice is a control with nothing to do.
 *
 * @var string $locale the locale being rendered
 * @var array<int, array<string, mixed>> $locales the languages offered: code, label, and url — this
 *      page in that language, or its home where there is no translation (D-043)
 */
if (count($locales) < 2) {
    return;
}

/*
 * NOT t(). The visitor-facing side has no translation mechanism: t() is the admin's, and
 * every string a visitor reads is either the owner's own content or, like the 404 page's
 * wording in PageController, a small per-locale map written where it is used. My first
 * version called t('site.languages') — a key that does not exist, in a mechanism this side
 * of the app does not use, which would have put a bare key into an aria-label.
 *
 * Falls back to English for a locale not listed, the way the 404 copy does.
 */
$label = ['en' => 'Languages', 'hr' => 'Jezici', 'de' => 'Sprachen'][$locale] ?? 'Languages';
?>
<nav class="locale-switcher" aria-label="<?= e($label) ?>">
<?php foreach ($locales as $option): ?>
    <a href="<?= e((string) ($option['url'] ?? Url::page((string) $option['code']))) ?>" hreflang="<?= e($option['code']) ?>" lang="<?= e($option['code']) ?>"<?= $option['code'] === $locale ? ' aria-current="true"' : '' ?>><?= e($option['label']) ?></a>
<?php endforeach; ?>
</nav>
