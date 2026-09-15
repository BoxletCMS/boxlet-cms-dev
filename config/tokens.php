<?php

// Default design token values, compiled to public/cache/tokens.css by
// App\Modules\Design\TokenCompiler. Each group => name pair becomes --group-name.
// Slice 4 replaces this source with the design_tokens table.
return [
    'color' => [
        'background' => '#ffffff',
        'surface' => '#f4f4f2',
        'text' => '#1c1c1a',
        'muted' => '#5c5c58',
        'accent' => '#1f5fbf',
    ],
    'font' => [
        'body' => 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
        'heading' => 'Georgia, "Times New Roman", serif',
    ],
    'text' => [
        'base' => '1rem',
        'lg' => '1.25rem',
        '2xl' => '2.441rem',
    ],
    'leading' => [
        'body' => '1.6',
        'heading' => '1.2',
    ],
    'space' => [
        's' => '0.5rem',
        'm' => '1rem',
        'l' => '2rem',
        'xl' => '4rem',
    ],
    'radius' => [
        'base' => '0.375rem',
    ],
    'container' => [
        'width' => '48rem',
    ],
];
