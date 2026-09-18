<?php

// Admin labels derive from the type, through t(): block.columns, block.columns.<field>,
// block.columns.items.<itemfield>, block.columns.layout.<layout>.
//
// The grid of D-008: one block, a row of two, three or four columns, every column holding
// the same bounded content. No other block goes inside a column, and a page stays a flat
// list of blocks. The layout is how many columns share a row, not how many there are:
// six items in a row of three is two rows, which is how a team or a list of services is set.
return [
    'type' => 'columns',
    'icon' => 'columns',
    'version' => 1,
    'fields' => [
        'heading' => ['type' => 'text', 'translatable' => true],
        'intro' => ['type' => 'textarea', 'translatable' => true],
        'items' => [
            'type' => 'repeater',
            'required' => true,
            // Four rows of three: a team page or a list of services, and still an editor a
            // person can scroll through.
            'max' => 12,
            'fields' => [
                'image' => ['type' => 'media'],
                'heading' => ['type' => 'text', 'translatable' => true],
                'body' => ['type' => 'richtext', 'translatable' => true],
                'link' => ['type' => 'link', 'translatable' => true],
            ],
        ],
        'image_shape' => ['type' => 'select', 'options' => ['wide', 'square', 'round']],
    ],
    'layouts' => ['two', 'three', 'four'],
    'defaults' => ['layout' => 'three'],
];
