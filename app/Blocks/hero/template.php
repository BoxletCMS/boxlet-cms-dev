<?php
/**
 * Hero block. Only class names here; every colour, size and font comes from CSS custom
 * properties in public/assets/site.css (SPEC §5.3).
 *
 * @var array<string, mixed> $content
 * @var array<string, mixed> $style
 * @var string $layout
 */
?>
<div class="hero">
    <div class="hero-text">
        <h1 class="hero-heading"><?= e($content['heading']) ?></h1>
<?php if ($content['subheading'] !== ''): ?>
        <p class="hero-subheading"><?= nl2br(e($content['subheading'])) ?></p>
<?php endif; ?>
<?php if ($content['cta']['url'] !== '' && $content['cta']['label'] !== ''): ?>
        <p class="hero-action"><a class="button" href="<?= e($content['cta']['url']) ?>"><?= e($content['cta']['label']) ?></a></p>
<?php endif; ?>
    </div>
<?php
/* A layout that reserves a picture area always draws one, with the same token-coloured
   placeholder image_text uses when nothing is chosen. An area the layout has set aside and
   then leaves empty is a hole the design never intended — measured on the demo's /services,
   where a split hero with no picture put the heading left, the subheading and button right,
   and nothing at all below them.

   A list rather than "always", because a layout that reserves nothing should draw nothing:
   a centred hero has no picture area to fill. */
$reservesPictureArea = in_array($layout, ['split'], true);
?>
<?php if ($reservesPictureArea || $content['image'] !== null): ?>
    <div class="hero-media">
        <div class="media-placeholder"<?= $content['image'] !== null ? ' data-media-id="' . e($content['image']) . '"' : '' ?> aria-hidden="true"></div>
    </div>
<?php endif; ?>
</div>
