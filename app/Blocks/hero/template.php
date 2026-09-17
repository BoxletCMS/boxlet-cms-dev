<?php
/**
 * Hero block. Only class names here; every colour, size and font comes from CSS custom
 * properties in public/assets/site.css (SPEC §5.3).
 *
 * @var array<string, mixed> $content
 * @var array<string, mixed> $style
 * @var string $layout
 * The picture shape is restated rather than imported: @phpstan-import-type resolves in a
 * class docblock, and a template has no class.
 *
 * @var array<int, array{id: int, filename: string, width: int, height: int, focalX: int, focalY: int, variants: array<string, array{width: int, height: int, formats: list<string>}>, alt: string}> $media id => resolved picture
 * @var bool $eager the first section on the page, which is never lazy-loaded
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

// hero and full: a hero picture can be asked to fill the whole column, so the largest
// preset it offers is the biggest one that exists (SPEC §5.5).
$picture = is_int($content['image'] ?? null) ? ($media[$content['image']] ?? null) : null;
$tag = \App\Modules\Media\MediaPicture::tag($picture, ['hero', 'full'], '(max-width: 40rem) 100vw, 50vw', $eager);
?>
<?php if ($reservesPictureArea || $content['image'] !== null): ?>
    <div class="hero-media">
<?php if ($tag !== ''): ?>
        <?= $tag ?>
<?php else: ?>
        <div class="media-placeholder"<?= $content['image'] !== null ? ' data-media-id="' . e($content['image']) . '"' : '' ?> aria-hidden="true"></div>
<?php endif; ?>
    </div>
<?php endif; ?>
</div>
