<?php

// Admin and installer UI strings. Every string shown in the admin comes from here via
// t('key'); :name placeholders are replaced by t()'s second argument.
return [
    'csrf.invalid' => 'This form has expired. Go back, reload the page and try again.',
    // Not a CSRF failure, though it arrives looking like one: PHP discards a post over
    // post_max_size whole, token and all. Saying the form expired would send someone to
    // reload and send the same oversized file again.
    'post.too_large' => 'That was larger than this server accepts (:limit per request), so none of it arrived. Nothing was saved.',

    'auth.title' => 'Log in',
    'auth.email' => 'Email',
    'auth.password' => 'Password',
    'auth.submit' => 'Log in',
    'auth.failed' => 'The email or password is incorrect.',
    'auth.throttled' => 'Too many login attempts. Try again in :minutes minutes.',

    'admin.brand' => 'Boxlet',
    'admin.skip' => 'Skip to content',
    'admin.nav.label' => 'Admin navigation',
    'admin.nav.dashboard' => 'Overview',
    // The rail's groups (D-052).
    'admin.nav.group.site' => 'Site',
    'admin.nav.group.content' => 'Content',
    'admin.nav.group.presentation' => 'Presentation',
    'admin.nav.group.administration' => 'Administration',
    'admin.your_login' => 'Your login',
    'admin.site_time' => 'The site\'s time zone, and the time there now',
    'admin.nav.pages' => 'Pages',
    'admin.nav.media' => 'Media',
    'admin.nav.design' => 'Design',
    'admin.nav.menus' => 'Menus',
    'admin.nav.forms' => 'Forms',
    'admin.nav.statistics' => 'Statistics',
    // The longest item in the bar, and deliberately: "Chrome" is what the code calls it,
    // not what the owner does. They came looking for the thing at the top of the page.
    'admin.nav.chrome' => 'Header and footer',
    'admin.nav.design_style' => 'Character and colours',
    'admin.nav.open' => 'Menu',
    'admin.nav.settings' => 'Settings',
    'admin.logout' => 'Log out',
    'admin.view_site' => 'View site',

    'admin.dashboard.title' => 'Dashboard',
    'admin.dashboard.intro' => 'Where your site stands, and the quickest ways back into it.',
    'admin.dashboard.published' => 'published, :drafts not yet',
    'admin.dashboard.pictures' => 'pictures in the library',
    'admin.dashboard.character' => 'the character the site wears',
    'admin.dashboard.menus' => 'menus built by hand',
    'admin.dashboard.next' => 'Carry on',
    'admin.dashboard.edit_home' => 'Edit the home page',
    'admin.dashboard.change_design' => 'Change the design',
    'admin.dashboard.add_pictures' => 'Add pictures',
    'admin.dashboard.maintenance' => 'Maintenance mode is on: visitors see a short message instead of the site.',
    'admin.dashboard.maintenance_link' => 'Switch it off in Settings',

    'richtext.toolbar' => 'Text formatting',
    'richtext.bold' => 'Bold',
    'richtext.italic' => 'Italic',
    'richtext.link' => 'Link',
    'richtext.unlink' => 'Unlink',
    'richtext.url' => 'Address',
    'richtext.url_placeholder' => 'https://example.com, an email or a phone number',
    'richtext.page' => 'Page',
    'richtext.heading_2' => 'Heading 2',
    'richtext.heading_3' => 'Heading 3',
    'richtext.heading_4' => 'Heading 4',
    'richtext.quote' => 'Quotation',
    'richtext.bullets' => 'Bulleted list',
    'richtext.numbers' => 'Numbered list',
    'richtext.undo' => 'Undo',
    'richtext.redo' => 'Redo',
    'richtext.plain' => 'Edit as HTML',
    'richtext.rich' => 'Edit as rich text',
    'richtext.paste_plain' => 'Ctrl+Shift+V pastes without formatting.',
];
