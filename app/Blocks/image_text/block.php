<?php

// Admin labels come from lang/en.php: block.image_text and block.image_text.<field>.
// image stores a media id; until the Media module (Slice 5) it renders a placeholder.
return [
    'type' => 'image_text',
    'label' => 'Image and text',
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
