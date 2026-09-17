<?php

// Site settings (PLAN.md D-028). The maintenance strings stay in update.php, where the
// rest of D-021's vocabulary lives — only the switch moved screens, not its words.

return [
    'settings.title' => 'Site settings',
    'settings.intro' => 'What this site is called, where it keeps time, and the pictures it shows when it has nothing more specific.',

    'settings.site_name' => 'Site name',
    'settings.site_name_hint' => 'Shown in the admin bar. Empty: Boxlet.',

    'settings.timezone' => 'Time zone',
    'settings.timezone_hint' => 'Dates are stored in UTC and shown in this zone.',
    'settings.timezone_invalid' => 'That is not a time zone this server knows. Nothing was saved.',

    'settings.pictures' => 'Pictures',
    'settings.logo' => 'Logo',
    'settings.logo_hint' => 'Used by the header once there is one to put it in.',
    'settings.favicon' => 'Favicon',
    'settings.favicon_hint' => 'The small icon a browser shows on the tab. A square picture works best; it is served at 200×200 and the browser sizes it down.',
    'settings.share_image' => 'Default sharing image',
    'settings.share_image_hint' => 'Used when a page has none of its own.',

    'settings.maintenance' => 'Maintenance mode',
    'settings.maintenance_message' => 'What visitors are told',
    'settings.maintenance_message_hint' => 'Empty: the standard wording.',

    'settings.save' => 'Save settings',
    'settings.saved' => 'Settings saved.',
    'settings.picture_gone' => 'One of the pictures chosen is no longer in the library, so that field was cleared.',
];
