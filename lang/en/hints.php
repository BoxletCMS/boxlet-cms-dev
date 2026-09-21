<?php

// What each field does, in words an owner who is not a programmer can act on (PLAN.md D-038,
// the owner's review: "much more descriptive, everywhere"). Drawn by field_hint(); a field
// with no entry here shows no description rather than its key.

return [
    // Blocks: hint.block.{type}.{field}
    'hint.block.hero.heading' => 'The large headline at the top of this section. A few words that say what the page is about.',
    'hint.block.hero.subheading' => 'One or two sentences under the headline that explain it.',
    'hint.block.hero.image' => 'A photograph beside or behind the headline, depending on the layout. Wide photographs work best.',
    'hint.block.hero.cta' => 'An optional button under the text, for the one next step you want the visitor to take.',
    'hint.block.text.heading' => 'An optional heading above the text.',
    'hint.block.text.body' => 'The text of this section. The toolbar gives you bold, italic, links, headings, quotes and lists.',
    'hint.block.image_text.heading' => 'The heading beside the picture.',
    'hint.block.image_text.body' => 'The text beside the picture.',
    'hint.block.image_text.image' => 'The picture next to the text. Which side it sits on is chosen under Layout.',
    'hint.block.image_text.image_fit' => '“Fill the frame” crops the picture to fill its space; “Show the whole image” keeps all of it, with room around it if its shape differs.',
    'hint.block.image_text.link' => 'An optional link under the text, such as “Read more”.',
    'hint.block.form.heading' => 'An optional heading above the form, such as “Write to us”.',
    'hint.block.form.intro' => 'An optional sentence or two, such as when you usually answer.',
    'hint.block.form.form' => 'Which form to show. Forms are made under Forms in the top bar; a page shows only the forms of its own language.',
    'hint.block.columns.heading' => 'An optional heading above the columns.',
    'hint.block.columns.intro' => 'An optional sentence or two under the heading, before the columns.',
    'hint.block.columns.items' => 'Each item is one column: a person, a service, a reason. How many share a row is chosen under Layout; more items than that start a new row.',
    'hint.block.columns.items.image' => 'An optional picture at the top of this column. Leave it empty for a column of words.',
    'hint.block.columns.items.heading' => 'The heading of this column, such as a name or a service.',
    'hint.block.columns.items.body' => 'A few lines about it.',
    'hint.block.columns.items.link' => 'An optional link under the text, such as “Read more”.',
    'hint.block.columns.image_shape' => 'The same shape for every picture in the block, so the row lines up. Round suits portraits of people.',

    // A block's arrangement and its section style: hint.layout, hint.style.{key}
    'hint.layout' => 'How this block arranges its parts. Each character starts a block in the arrangement that suits it; change it here for this section only.',
    'hint.style.surface' => 'The background of this section, from the site’s own colours: plain, a light tint, the contrast colour, a picture or a gradient. Text stays readable on every one.',
    'hint.style.rhythm' => 'How much space there is above and below this section.',
    'hint.style.width' => 'How wide the content of this section may run: narrow suits reading, wide and full suit pictures.',
    'hint.style.align' => 'Whether the text of this section starts at the left or is centred.',
    'hint.style.divider' => 'The edge between this section and the one above it: none, a thin line, a slant or a curve.',

    // A page's settings
    'hint.page.title' => 'The page’s name. Visitors see it as the big heading at the top; it is also the page’s title in search results and browser tabs, unless you set one below.',
    'hint.page.parent' => 'Places this page under another in the page list, to keep related pages together. It does not change the page’s address.',
    'hint.page.locale' => 'The language this page is written in. A page in another language than the first gets that language’s prefix in its address, such as /hr/.',
    'hint.page.template' => 'A set of blocks to start the page with, so it is not empty. You can add, remove and reorder blocks afterwards.',
    'hint.page.status' => 'A draft is visible only to you. Published puts the page on the site for everyone.',

    // The Design screen: hint.design.{decision}
    'hint.design.secondary' => 'Optional. A second colour for contrast sections — bands of colour that break up a long page. Without one, a deep shade of the main colour is used.',
    'hint.design.surface_contrast' => 'How strongly tinted and contrast sections stand apart from plain ones.',
    'hint.design.typography' => 'The pair of typefaces for headings and for text, chosen to work together. The fonts are served from your own site, not from anyone else’s.',
    'hint.design.text_size' => 'How big the text itself is. The scale below is a different question: how much bigger each heading is than the one under it.',
    'hint.design.scale' => 'How much bigger each heading level is than the one below it. A larger step gives dramatic headlines; a smaller one a quieter page.',
    'hint.design.spacing' => 'The basic unit of space inside and between sections. Everything grows from it evenly.',
    'hint.design.radius' => 'How rounded corners are — on buttons, pictures and cards.',
    'hint.design.shadow' => 'Whether cards and pictures cast a shadow, and what kind.',
    'hint.design.container' => 'How wide the page’s content runs on a large screen.',
    'hint.design.header_width' => 'Whether the header lines up with the page’s content or runs the full width of the window.',
    'hint.design.boxed' => '“Yes” shows the page as a sheet with a margin of colour around it on large screens.',

    // The Header and footer screen: hint.look.{choice}
    'hint.look.header_layout' => 'Where the logo, menu and button sit: name left and menu right, everything centred, laid over the first section of each page, or staying at the top while visitors scroll.',
    'hint.look.footer_layout' => 'The footer’s words in one column, or beside the menu.',
    'hint.look.header_surface' => 'The header’s background, from the site’s own colours.',
    'hint.look.footer_surface' => 'The footer’s background, from the site’s own colours.',
    'hint.look.density' => 'How much room there is around the header’s and footer’s contents.',
    'hint.look.header_rule' => 'A thin line under the header, between it and the page.',
    'hint.look.logo_size' => 'How tall the logo is drawn. Its shape never changes.',

    // Media
    'hint.media.caption' => 'Optional words kept with the picture, for blocks that show a caption under it. None of today’s blocks does yet.',

    // Menus
    'hint.forms.locale' => 'The language of the form\'s labels and replies. A page shows the forms of its own language, so each translation can have its own.',
    'hint.menus.locale' => 'The language this menu is for. Each language has its own menus, so every translation can name its items in its own words.',
];
