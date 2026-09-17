<?php

// Admin labels derive from the type, through t(): block.image_text, block.image_text.<field>,
// block.image_text.layout.<layout>.
// image stores a media id; until the Media module (Slice 5) it renders a placeholder.
return [
    'type' => 'image_text',
    'icon' => 'image-text',
    'version' => 1,
    'fields' => [
        'heading' => ['type' => 'text', 'translatable' => true],
        'body' => ['type' => 'richtext', 'required' => true, 'translatable' => true],
        'image' => ['type' => 'media'],
        'image_fit' => ['type' => 'select', 'options' => ['cover', 'contain']],
        'link' => ['type' => 'link', 'translatable' => true],
    ],
    'layouts' => ['image-left', 'image-right'],
    'defaults' => ['layout' => 'image-left'],
];
