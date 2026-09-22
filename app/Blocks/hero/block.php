<?php

// Admin labels derive from the type, through t(): block.hero, block.hero.<field>,
// block.hero.layout.<layout>.
return [
    'type' => 'hero',
    'icon' => 'panel-top',
    'version' => 1,
    'fields' => [
        'heading' => ['type' => 'text', 'required' => true, 'translatable' => true, 'sample' => 'preview.hero.heading'],
        'subheading' => ['type' => 'textarea', 'translatable' => true, 'sample' => 'preview.hero.subheading'],
        'image' => ['type' => 'media'],
        'cta' => ['type' => 'link', 'translatable' => true, 'sample' => 'preview.hero.cta'],
    ],
    'layouts' => ['left', 'center', 'split'],
    'defaults' => ['layout' => 'center'],
];
