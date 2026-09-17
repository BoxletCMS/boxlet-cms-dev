<?php

/**
 * One new block, as the two pieces the editor needs: the section the canvas shows and
 * the field group the form submits. Both are rendered by the server from the block
 * definition, so the browser never needs to know what fields a block has.
 *
 * Template elements are inert: the browser parses them without running scripts, loading
 * images or applying styles until the editor moves them where they belong.
 *
 * @var int $index
 * @var array{id: int|null, type: string, content: array<string, mixed>|null, style: array<string, string|int|null>, layout: string} $block
 * @var array<string, string> $errors
 * @var string $character
 * @var \App\Core\Blocks $registry
 * @var list<array{id: int, name: string}> $pictures
 * @var string $canvasHtml
 */
?>
<template data-block-canvas><?= $canvasHtml ?></template>
<template data-block-fields><div class="panel-block" data-block-group="<?= e($index) ?>" hidden>
<?php require __DIR__ . '/block.php'; ?>
</div></template>
