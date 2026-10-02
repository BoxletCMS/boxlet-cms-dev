<?php
/**
 * Text block. body is richtext, reduced to the safe-HTML whitelist when the page is
 * saved, so it is output as HTML here. Everything else is escaped.
 *
 * @var array<string, mixed> $content
 * @var array<string, mixed> $style
 * @var string $layout
 */
?>
<div class="text">
<?php if (edit_show($content['heading'] !== '')): ?>
    <h2 class="text-heading"<?= edit_attr('heading') ?>><?= e($content['heading']) ?></h2>
<?php endif; ?>
    <div class="richtext"<?= edit_attr('body') ?>><?= $content['body'] ?></div>
</div>
