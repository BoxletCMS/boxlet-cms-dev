<?php

// Admin labels derive from the type in lang/en.php: block.hero, block.hero.<field>,
// block.hero.layout.<layout>.
return [
    'type' => 'hero',
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
