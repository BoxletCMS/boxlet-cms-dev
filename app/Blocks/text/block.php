<?php

// Admin labels come from lang/en.php: block.text and block.text.<field>.
return [
    'type' => 'text',
    'label' => 'Text',
    'icon' => 'text',
    'version' => 1,
    'fields' => [
        'heading' => ['type' => 'text', 'translatable' => true],
        'body' => ['type' => 'richtext', 'required' => true, 'translatable' => true],
    ],
    'layouts' => ['single', 'columns'],
    'defaults' => ['layout' => 'single'],
];
