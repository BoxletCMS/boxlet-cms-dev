<?php

// The site's header (PLAN.md D-028, D-030). Discovered by a SECOND registry over
// app/Chrome, not app/Blocks: it is drawn by the same machinery so it inherits the design
// tokens and the section style layers, but it must never appear in the page editor's block
// library — a header dropped into the middle of an article is not a thing.
//
// THERE IS NO MENU FIELD. The chrome screen chooses which menu by name, the renderer
// resolves it once per request, and the template receives it in $resolved — the same way
// pictures arrive already resolved, so a template asks the database nothing. No field type
// points at a menu and none was added: FIELD_TYPES is frozen (SPEC §5.3).
//
// The layouts are the per-character header variants D-028 names.

return [
    'type' => 'header',
    'icon' => 'header',
    'version' => 1,
    'fields' => [
        'logo' => ['type' => 'media'],
        'button' => ['type' => 'link', 'translatable' => true],
    ],
    'layouts' => ['left', 'centred', 'transparent', 'sticky'],
    'defaults' => ['layout' => 'left'],
];
