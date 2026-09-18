<?php
/**
 * The site header (PLAN.md D-028, D-030). Class names only; every colour, size and font
 * comes from CSS custom properties in public/assets/site.css (SPEC §5.3).
 *
 * @var array<string, mixed> $content logo and button, from the chrome screen
 * @var array<string, mixed> $style   section style layers, as for any block
 * @var string $layout the character's header variant: left, centred, transparent, sticky
 * @var array<int, array{id: int, filename: string, width: int, height: int, focalX: int, focalY: int, variants: array<string, array{width: int, height: int, formats: list<string>}>, alt: string}> $media id => resolved picture
 * @var bool $eager
 * @var array<string, mixed> $resolved values the renderer resolved; today: the menu
 */

/* The menu arrives resolved — label, url and one level of children — or empty when the
   chrome names a menu that does not exist any more. MenuTree::forVisitors() already
   returns [] in that case, so a deleted menu draws no nav rather than broken markup. */
$menu = is_array($resolved['menu'] ?? null) ? $resolved['menu'] : [];

$logo = is_int($content['logo'] ?? null) ? ($media[$content['logo']] ?? null) : null;
$logoTag = \App\Modules\Media\MediaPicture::tag($logo, ['thumb', 'card'], '200px', true);
$button = $content['button'];
?>
<div class="site-header">
<?php if ($logoTag !== ''): ?>
    <a class="site-logo" href="<?= e(\App\Support\Url::page($locale ?? '')) ?>"><?= $logoTag ?></a>
<?php endif; ?>

<?php if ($menu !== []): ?>
    <nav class="site-nav">
        <ul>
<?php foreach ($menu as $item): ?>
            <li>
                <a href="<?= e($item['url']) ?>"><?= e($item['label']) ?></a>
<?php if ($item['children'] !== []): ?>
                <ul class="site-nav-children">
<?php foreach ($item['children'] as $child): ?>
                    <li><a href="<?= e($child['url']) ?>"><?= e($child['label']) ?></a></li>
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
