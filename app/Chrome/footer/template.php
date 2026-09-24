<?php
/**
 * The site footer (PLAN.md D-028, D-030, D-113) — the only one on the page. Class names
 * only; colours, sizes and fonts come from CSS custom properties (SPEC §5.3).
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
 * @var string $layout simple, centred, columns, menu_first or three
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
/* Rich text since D-113, plain before: one rule (ChromeWords::isHtml) decides which the
   stored string is, so the page and the editor never disagree about it. Plain text is drawn
   exactly as it always was, so no footer written before D-113 moved. */
$text = is_string($content['text'] ?? null) ? $content['text'] : '';
$textHtml = \App\Modules\Settings\ChromeWords::isHtml($text) ? $text : nl2br(e($text));
?>
<?php /* The menu's columns and the small-print row are CLASSES, not custom properties: the
         admin's policy refuses a style attribute, and a closed set is exactly what a class
         is for (D-067, D-113). `own-colour` when the footer takes a colour of the owner's
         (D-076): the class is what lets chrome.css set the section's tokens with no
         fallback, because a fallback naming the token itself is a cycle (D-110). */ ?>
<div class="site-footer density-<?= e($look['density'] ?? 'normal') ?> footer-cols-<?= e($look['footer_columns'] ?? '2') ?> foot-<?= e($look['small_print_row'] ?? 'left') ?><?= ($resolved['own'] ?? false) === true ? ' own-colour' : '' ?>">
<?php if ($text !== ''): ?>
    <div class="site-footer-text"><?= $textHtml ?></div>
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
<?php /* THE LAST ROW (D-113): the languages and the small print, side by side, centred, or
         one under the other — one box, so the arrangements can place it as one thing. Drawn
         only when it would hold something: the switcher draws nothing with one language
         (its own rule), and an empty small print is nothing. */ ?>
<?php if (count($locales) > 1 || $content['small_print'] !== '' || $credit !== ''): ?>
    <div class="site-footer-foot">
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
<?php endif; ?>
</div>
