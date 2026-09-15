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
<?php if ($content['image'] !== null): ?>
    <div class="hero-media">
        <div class="media-placeholder" data-media-id="<?= e($content['image']) ?>" aria-hidden="true"></div>
    </div>
<?php endif; ?>
</div>
