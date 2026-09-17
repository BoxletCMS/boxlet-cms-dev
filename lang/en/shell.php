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
    'admin.nav.dashboard' => 'Dashboard',
    'admin.nav.pages' => 'Pages',
    'admin.nav.media' => 'Media',
    'admin.nav.design' => 'Design',
    'admin.nav.menus' => 'Menus',
    'admin.nav.settings' => 'Settings',
    'admin.logout' => 'Log out',

    'admin.dashboard.title' => 'Dashboard',
    'admin.dashboard.intro' => 'You are logged in. Create and edit your site\'s content under Pages, and choose how it looks under Design.',

    'richtext.toolbar' => 'Text formatting',
    'richtext.bold' => 'Bold',
    'richtext.italic' => 'Italic',
    'richtext.link' => 'Link',
    'richtext.unlink' => 'Unlink',
    'richtext.url' => 'Address',
    'richtext.url_placeholder' => 'https://example.com',
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
