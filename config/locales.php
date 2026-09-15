<?php

// Slice 6 moves locales into the `locales` table. From then on this file is only the
// installer's seed source, never a second list of enabled locales.
return [
    'default' => 'en',
    'enabled' => [
        'en' => ['label' => 'English'],
        'hr' => ['label' => 'Hrvatski'],
    ],
];
