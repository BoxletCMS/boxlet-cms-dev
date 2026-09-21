<?php
/**
 * The site footer (PLAN.md D-028, D-030) — the only one on the page. Class names only;
 * colours, sizes and fonts come from CSS custom properties (SPEC §5.3).
 *
 * THE LANGUAGE SWITCHER LIVES HERE, in the one footer the site has, rather than in the
 * frame around it — otherwise "one footer" would be true of the markup and false of what
 * the owner edits. It arrives through $locale and $locales, which are facts about the
 * request and so are their own arguments, not another key in $resolved (D-030).
 *
 * It is the SAME partial the layout used, included rather than copied: two copies of one
 * switcher drift apart a slice later. The partial's home is a Pages view, which is a seam
 * worth watching — if chrome grows a second shared piece, it should move somewhere neutral.
 *
 * @var array<string, mixed> $content text and small print, from the chrome screen
 * @var array<string, mixed> $style
 * @var string $layout simple or columns
 * @var array<int, array<string, mixed>> $media
 * @var bool $eager
 * @var array<string, mixed> $resolved values the renderer resolved: the menu, each entry
 *                                   marked when it is the page being drawn, the look, and
 *                                   the Boxlet credit when the owner leaves it on
 * @var string $locale the locale being rendered
 * @var array<int, array<string, mixed>> $locales enabled locales
 */
$menu = is_array($resolved['menu'] ?? null) ? $resolved['menu'] : [];
$look = is_array($resolved['look'] ?? null) ? $resolved['look'] : [];
$credit = is_string($resolved['credit'] ?? null) ? $resolved['credit'] : '';
?>
<?php /* The menu's columns are a CLASS, not a custom property: the admin's policy refuses a
         style attribute, and a closed set of three is exactly what a class is for (D-067). */ ?>
<div class="site-footer density-<?= e($look['density'] ?? 'normal') ?> footer-cols-<?= e($look['footer_columns'] ?? '2') ?>">
<?php if ($content['text'] !== ''): ?>
    <div class="site-footer-text"><?= nl2br(e($content['text'])) ?></div>
<?php endif; ?>

<?php if ($menu !== []): ?>
    <nav class="site-footer-nav">
        <ul>
<?php foreach ($menu as $item): ?>
            <li><a href="<?= e($item['url']) ?>"<?= !empty($item['current']) ? ' aria-current="page"' : '' ?>><?= e($item['label']) ?></a></li>
<?php endforeach; ?>
        </ul>
    </nav>
<?php endif; ?>

<?php /* One level only, deliberately: a footer menu with submenus is a sitemap, and the
         footer is not where a visitor navigates a hierarchy. Children of a footer item are
         left out rather than flattened, which would put a child beside its own parent. */ ?>
<?php /* Draws nothing when one locale is enabled, which is the behaviour it has always had:
         a switcher offering a single choice is a control with nothing to do. */ ?>
<?php require __DIR__ . '/../../Modules/Pages/views/partials/locale-switcher.php'; ?>

<?php if ($content['small_print'] !== '' || $credit !== ''): ?>
    <p class="site-small-print">
<?= $content['small_print'] !== '' ? e($content['small_print']) : '' ?>
<?php if ($credit !== ''): ?>
        <?php /* One line, the last thing on the page, in the small print where a credit
                 belongs — not a badge and not an image. rel="noopener" because it leaves
                 the site; no target, because a visitor who wants a new tab has a browser
                 that gives them one. */ ?>
        <span class="site-credit"><a href="<?= e($credit) ?>" rel="noopener"><?= e(site_t('site.credit', $locale)) ?></a></span>
<?php endif; ?>
    </p>
<?php endif; ?>
</div>
