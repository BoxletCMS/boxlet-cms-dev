<?php

// Admin labels derive from the type in lang/en.php: block.text, block.text.<field>,
// block.text.layout.<layout>.
return [
    'type' => 'text',
    'icon' => 'text',
    'version' => 1,
    'fields' => [
        'heading' => ['type' => 'text', 'translatable' => true],
        'body' => ['type' => 'richtext', 'required' => true, 'translatable' => true],
    ],
    'layouts' => ['single', 'columns'],
    'defaults' => ['layout' => 'single'],
];
