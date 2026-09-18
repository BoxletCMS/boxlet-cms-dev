<?php

// The site's footer (PLAN.md D-028, D-030). One per site and one per page, discovered by
// the chrome registry over app/Chrome rather than app/Blocks.
//
// It owns the only <footer> on the page, which is why the language switcher moved into it
// rather than staying a second footer in the layout. The switcher is not a field: it is
// drawn from the enabled locales, and it draws nothing at all when there is only one.
//
// The menu arrives in $resolved, like the header's — see app/Chrome/header/block.php.

return [
    'type' => 'footer',
    'icon' => 'footer',
    'version' => 1,
    'fields' => [
        'text' => ['type' => 'textarea', 'translatable' => true],
        'small_print' => ['type' => 'text', 'translatable' => true],
    ],
    'layouts' => ['simple', 'columns'],
    'defaults' => ['layout' => 'simple'],
];
