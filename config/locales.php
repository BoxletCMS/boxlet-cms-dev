<?php

// The primary locale is chosen at install time and never changes. It renders without a
// URL prefix; every other enabled locale always carries one (SPEC §5.1).
//
// Enabled locales are rows, not a map keyed by code: PHP turns numeric-string array
// keys into ints, so only a value can guarantee a code is a string. The row shape
// matches the `locales` table.
//
// Slice 6 moves locales into the `locales` table. From then on this file is only the
// installer's seed source, never a second list of enabled locales.
return [
    'primary' => 'en',
    'enabled' => [
        ['code' => 'en', 'label' => 'English'],
        ['code' => 'hr', 'label' => 'Hrvatski'],
    ],
];
