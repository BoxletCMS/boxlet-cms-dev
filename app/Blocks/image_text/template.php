<?php
/**
 * Image and text block. body is richtext, reduced to the safe-HTML whitelist on save.
 * The image is a placeholder until the Media module exists (Slice 5).
 *
 * @var array<string, mixed> $content
 * @var array<string, mixed> $style
 * @var string $layout
 */
?>
<div class="image-text fit-<?= e($content['image_fit']) ?>">
    <div class="image-text-media">
        <div class="media-placeholder"<?= $content['image'] !== null ? ' data-media-id="' . e($content['image']) . '"' : '' ?> aria-hidden="true"></div>
    </div>
    <div class="image-text-body">
<?php if ($content['heading'] !== ''): ?>
        <h2 class="image-text-heading"><?= e($content['heading']) ?></h2>
<?php endif; ?>
        <div class="richtext"><?= $content['body'] ?></div>
<?php if ($content['link']['url'] !== '' && $content['link']['label'] !== ''): ?>
        <p><a href="<?= e($content['link']['url']) ?>"><?= e($content['link']['label']) ?></a></p>
<?php endif; ?>
    </div>
</div>
