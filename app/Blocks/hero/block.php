<?php

// Admin labels come from lang/en.php: block.hero and block.hero.<field>.
return [
    'type' => 'hero',
    'label' => 'Hero',
    'icon' => 'hero',
    'version' => 1,
    'fields' => [
        'heading' => ['type' => 'text', 'required' => true, 'translatable' => true],
        'subheading' => ['type' => 'textarea', 'translatable' => true],
        'image' => ['type' => 'media'],
        'cta' => ['type' => 'link', 'translatable' => true],
    ],
    'layouts' => ['left', 'center', 'split'],
    'defaults' => ['layout' => 'center'],
];
