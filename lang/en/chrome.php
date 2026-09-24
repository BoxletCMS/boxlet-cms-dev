<?php

// The site's header and footer (PLAN.md D-028, D-030). A new concern, so a new file:
// t() merges every file in the locale's directory, and a key defined twice is a test
// failure rather than a silent winner.
//
// Written IN THE SAME BREATH as views/chrome.php. Calling t() for a key that does not
// exist yet put a bare key on a screen in 5a, and an untranslated aria-label on the front
// end earlier today; the order is what prevents it, not care.

return [
    'chrome.title' => 'Header and footer',
    'chrome.intro' => 'What sits at the top and bottom of every page. Set once for the whole site.',

    'chrome.shared' => 'The same in every language',
    'chrome.words' => 'Words',
    // Each tab holds its own half of the words (D-111), folded per language when there are several.
    'chrome.words.header' => 'Header words',
    'chrome.words.footer' => 'Footer words',


    'chrome.logo_where' => 'The logo is set with the site’s other pictures, under',
    'chrome.logo_where_link' => 'Settings → Branding.',
    'chrome.menu' => 'Menu',
    'chrome.menu_hint' => 'Which menu the header and footer show. Menus are built under Menus; a menu with the same name in each language gives every translation its own words.',
    'chrome.menu_none' => 'No menu',
    'chrome.no_menus' => 'There are no menus yet. Build one under Menus and it will be offered here.',
    'chrome.menu_gone' => 'The menu you had chosen no longer exists, so it was cleared.',

    // "Button" alone read as a toggle or a section heading on the rendered screen, with
    // "Button address" directly under it. It is the words ON the button, so it says so.
    'chrome.button_label' => 'Button text',
    'chrome.button_label_hint' => 'The words on the button, such as “Get in touch”. Choosing a page fills in its title; change it if you like. Without text no button is shown.',
    'chrome.button_url' => 'Button links to',
    'chrome.button_url_hint' => 'An optional button at the right of the header, for the one thing you most want visitors to do. Choose one of your pages, or “Another address…” to link anywhere else. Leave both empty for no button.',
    'chrome.button_address' => 'Button address',
    'chrome.button_address_hint' => 'Filled in when you choose a page. For anything else type it here: /contact, https://example.com, an email address or a phone number. An email opens the visitor’s mail app; a phone number can be tapped to call on a phone.',
    'chrome.button_url_refused' => 'That address was not accepted, so it was not saved.',

    'chrome.text' => 'Footer text',
    'chrome.text_hint' => 'A line or two at the foot of every page — who you are, where to find you. Line breaks are kept.',
    'chrome.small_print' => 'Small print',
    'chrome.small_print_hint' => 'The very last line of every page: a copyright, a company number, whatever the law asks for.',

    'chrome.look' => 'How they look',
    'chrome.look_intro' => 'Each choice follows the character until you pick something else. Colours come from the palette, so every combination stays readable.',
    // On the Appearance screen the follow state is a segment and a badge (D-065): the
    // segment names what the character gives, the badge says the choice is still its.
    'chrome.look.follow_short' => 'Follow: :value',
    'chrome.look.following' => 'following',
    'chrome.look.follow' => 'As the character has it: :value',
    'chrome.look.header_layout' => 'Header arrangement',
    // SHORT (D-111): a segment is one or two words, and the hint says the rest. "Name left,
    // menu right" and "Stays at the top when scrolling" broke the rows into ragged halves.
    'chrome.look.header_layout.left' => 'Left',
    'chrome.look.header_layout.centred' => 'Centred',
    'chrome.look.header_layout.transparent' => 'Over the top',
    'chrome.look.header_layout.sticky' => 'Sticky',
    'chrome.look.footer_layout' => 'Footer arrangement',
    'chrome.look.footer_layout.simple' => 'One column',
    'chrome.look.footer_layout.columns' => 'Beside the menu',
    'chrome.look.header_surface' => 'Header surface',
    'chrome.look.header_surface.plain' => 'Plain',
    'chrome.look.header_surface.tinted' => 'Tinted',
    'chrome.look.header_surface.contrast' => 'Contrast',
    'chrome.look.footer_surface' => 'Footer surface',
    'chrome.look.footer_surface.plain' => 'Plain',
    'chrome.look.footer_surface.tinted' => 'Tinted',
    'chrome.look.footer_surface.contrast' => 'Contrast',
    'chrome.look.density' => 'Density',
    'chrome.look.density.compact' => 'Compact',
    'chrome.look.density.normal' => 'Normal',
    'chrome.look.density.roomy' => 'Roomy',
    'chrome.look.header_rule' => 'Rule under the header',
    'chrome.look.header_rule.on' => 'Yes',
    'chrome.look.header_rule.off' => 'No',
    'chrome.look.footer_columns' => 'Footer menu columns',
    'chrome.look.footer_columns.2' => 'One',
    'chrome.look.footer_columns.3' => 'Two',
    'chrome.look.footer_columns.4' => 'Three',
    'chrome.look.logo_size' => 'Logo size',
    'chrome.look.logo_size.small' => 'Small',
    'chrome.look.logo_size.medium' => 'Medium',
    'chrome.look.logo_size.large' => 'Large',

    'chrome.save' => 'Save header and footer',
    'chrome.saved' => 'Header and footer saved.',
];
