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

    'chrome.logo' => 'Logo',
    'chrome.logo_hint' => 'Shown in the header, linking to the home page. Without one the header shows the menu alone.',
    'chrome.logo_gone' => 'The picture you had chosen is no longer in the library, so the logo was cleared.',

    'chrome.menu' => 'Menu',
    'chrome.menu_hint' => 'Chosen by name: each language shows its own menu of that name.',
    'chrome.menu_none' => 'No menu',
    'chrome.no_menus' => 'There are no menus yet. Build one under Menus and it will be offered here.',
    'chrome.menu_gone' => 'The menu you had chosen no longer exists, so it was cleared.',

    // "Button" alone read as a toggle or a section heading on the rendered screen, with
    // "Button address" directly under it. It is the words ON the button, so it says so.
    'chrome.button_label' => 'Button label',
    'chrome.button_label_hint' => 'Optional. Left empty, a button to a page uses the page title; a button to an address needs a label, or none is shown.',
    'chrome.button_url' => 'Button links to',
    'chrome.button_url_hint' => 'A page of this site, or another address starting with /, #, ? or http, https, mailto, tel.',
    'chrome.button_url_refused' => 'That address was not accepted, so it was not saved.',

    'chrome.text' => 'Footer text',
    'chrome.text_hint' => 'A line or two under the page. Line breaks are kept.',
    'chrome.small_print' => 'Small print',
    'chrome.small_print_hint' => 'The last line: a copyright, a company number, whatever the law asks for.',

    'chrome.look' => 'How they look',
    'chrome.look_intro' => 'Each choice follows the character until you pick something else. Colours come from the palette, so every combination stays readable.',
    'chrome.look.follow' => 'As the character has it: :value',
    'chrome.look.header_layout' => 'Header arrangement',
    'chrome.look.header_layout.left' => 'Name left, menu right',
    'chrome.look.header_layout.centred' => 'Centred',
    'chrome.look.header_layout.transparent' => 'Over the first section',
    'chrome.look.header_layout.sticky' => 'Stays at the top when scrolling',
    'chrome.look.footer_layout' => 'Footer arrangement',
    'chrome.look.footer_layout.simple' => 'One column',
    'chrome.look.footer_layout.columns' => 'Words beside the menu',
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
    'chrome.look.logo_size' => 'Logo size',
    'chrome.look.logo_size.small' => 'Small',
    'chrome.look.logo_size.medium' => 'Medium',
    'chrome.look.logo_size.large' => 'Large',

    'chrome.save' => 'Save header and footer',
    'chrome.saved' => 'Header and footer saved.',
];
