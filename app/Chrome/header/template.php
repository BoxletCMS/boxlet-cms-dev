<?php
/**
 * The site header (PLAN.md D-028, D-030). Class names only; every colour, size and font
 * comes from CSS custom properties in public/assets/chrome.css (SPEC §5.3).
 *
 * @var array<string, mixed> $content logo and button, from the chrome screen
 * @var array<string, mixed> $style   section style layers, as for any block
 * @var string $layout the character's header variant: left, centred, transparent, sticky
 * @var array<int, array{id: int, filename: string, width: int, height: int, focalX: int, focalY: int, variants: array<string, array{width: int, height: int, formats: list<string>}>, alt: string, version: string}> $media id => resolved picture
 * @var bool $eager
 * @var array<string, mixed> $resolved values the renderer resolved: the menu, each entry
 *                                   marked when it is the page being drawn, and the look
 *                                   (ChromeLook)
 */

/* The menu arrives resolved — label, url and one level of children — or empty when the
   chrome names a menu that does not exist any more. MenuTree::forVisitors() already
   returns [] in that case, so a deleted menu draws no nav rather than broken markup. */
$menu = is_array($resolved['menu'] ?? null) ? $resolved['menu'] : [];

$logo = is_int($content['logo'] ?? null) ? ($media[$content['logo']] ?? null) : null;
/* `full`, the one preset that is never cropped (SPEC §5.5): a logo keeps the shape it was
   uploaded in (D-038). thumb and card cut a wide logo down to its middle. */
$logoTag = \App\Modules\Media\MediaPicture::tag($logo, ['full'], '20em', true);
$button = $content['button'];

/* The look (PLAN.md D-032, D-036): closed sets resolved by ChromeLook, so each is a class
   and nothing else. Surface and layout arrive as the section's own layers. */
$look = is_array($resolved['look'] ?? null) ? $resolved['look'] : [];
/* A colour of the owner's own (D-076) is a CLASS on the bar, and the class is what lets
   chrome.css set the section's tokens from the --chrome-header-* ones with no fallback —
   a fallback naming the token itself is a cycle, and a cycle is a token that quietly
   becomes nothing (D-110). Never on a header laid over the first section: that layout
   exists to paint nothing and take the colours beneath it. */
$ownColour = ($resolved['own'] ?? false) === true && $layout !== 'transparent';
$classes = 'site-header density-' . ($look['density'] ?? 'normal')
    . ' logo-' . ($look['logo_size'] ?? 'medium')
    . (($look['header_rule'] ?? 'off') === 'on' ? ' has-rule' : '')
    . ($ownColour ? ' own-colour' : '');

/* NOT t(), for the reason the language switcher gives: site_t() says the site's own few
   words in the page's language (lang/{code}/site.php, D-044). */
$menuLabel = site_t('site.menu', (string) ($locale ?? ''));
/* THE SITE'S NAME STANDS IN FOR A LOGO IT DOES NOT HAVE (D-110). A header that drew only
   the menu left a site with no logo — which is most sites on their first day — with no
   name anywhere on the page. The name is set in the heading face by chrome.css, so it
   takes the character like everything else. */
$siteName = is_string($resolved['site_name'] ?? null) ? trim($resolved['site_name']) : '';
?>
<div class="<?= e($classes) ?>" data-site-header>
<?php if ($logoTag !== ''): ?>
    <a class="site-logo" href="<?= e(\App\Support\Url::page($locale ?? '')) ?>"><?= $logoTag ?></a>
<?php elseif ($siteName !== ''): ?>
    <a class="site-logo site-name" href="<?= e(\App\Support\Url::page($locale ?? '')) ?>"><?= e($siteName) ?></a>
<?php endif; ?>

<?php if ($menu !== []): ?>
<?php /* The toggle is for narrow screens and exists only once site-nav.js has run: it is
         born hidden, so without a script there is no button that does nothing, and the
         navigation simply wraps under the logo. */ ?>
    <button type="button" class="site-nav-toggle" aria-expanded="false" aria-controls="site-nav" hidden data-site-nav-toggle><?= e($menuLabel) ?></button>
    <nav class="site-nav" id="site-nav" aria-label="<?= e($menuLabel) ?>">
        <ul>
<?php foreach ($menu as $i => $item): ?>
            <li<?= !empty($item['current_parent']) ? ' class="is-current-parent"' : '' ?>>
                <a href="<?= e($item['url']) ?>"<?= !empty($item['current']) ? ' aria-current="page"' : '' ?>><?= e($item['label']) ?></a>
<?php if ($item['children'] !== []): ?>
<?php /* A submenu opens by its own button, never by hover alone: a menu that appears only
         under a pointer is invisible to a keyboard and to a finger. Without a script the
         button stays hidden and the submenu is simply listed under its parent. */ ?>
                <button type="button" class="site-nav-more" aria-expanded="false" aria-controls="site-nav-<?= e((string) $i) ?>" aria-label="<?= e($item['label']) ?>" hidden data-site-nav-more><span aria-hidden="true">▾</span></button>
                <ul class="site-nav-children" id="site-nav-<?= e((string) $i) ?>">
<?php foreach ($item['children'] as $child): ?>
                    <li><a href="<?= e($child['url']) ?>"<?= !empty($child['current']) ? ' aria-current="page"' : '' ?>><?= e($child['label']) ?></a></li>
<?php endforeach; ?>
                </ul>
<?php endif; ?>
            </li>
<?php endforeach; ?>
        </ul>
    </nav>
<?php endif; ?>

<?php /* Optional by design (D-028): a header with no call to action draws none, rather
         than an empty button shape. Both halves are required — a label with no address is
         not a link, and an address with no label is nothing to click. */ ?>
<?php if ($button['url'] !== '' && $button['label'] !== ''): ?>
    <p class="site-header-action"><a class="button" href="<?= e($button['url']) ?>"><?= e($button['label']) ?></a></p>
<?php endif; ?>
</div>
