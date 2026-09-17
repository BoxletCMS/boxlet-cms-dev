<?php

// Demo site content. Between them the pages use every block type, every layout, and
// every value of every section style (tests/demo_test.php checks this). The copy is
// deliberately plain so that any character looks at home on it.
// Each block: [type, content, section style, layout].
//
// NO MEDIA IDS HERE. A media field is left out, which normalises to null: an id for a
// picture that was never uploaded is a dangling reference, and the first photograph
// that happens to take that number is silently adopted by the page. Photographs arrive
// with D-022 and are set explicitly then.

return [
    [
        'slug' => '',
        'title' => 'Northwind Studio',
        'blocks' => [
            ['hero', [
                'heading' => 'Small studio, carefully made websites',
                'subheading' => 'We design and build sites for independent shops, practices and makers.',
                'cta' => ['label' => 'See what we do', 'url' => '/services'],
            ], ['surface' => 'gradient', 'rhythm' => 'airy', 'align' => 'center'], 'center'],
            ['image_text', [
                'heading' => 'Design first, then everything else',
                'body' => '<p>Every project starts with how the site should feel: calm and editorial, loud and confident, or somewhere in between.</p><p>The pages, the colours and the type all follow from those few decisions.</p>',
                'link' => ['label' => 'How we work', 'url' => '/about'],
            ], ['surface' => 'tinted'], 'image-left'],
            ['text', [
                'heading' => 'What clients say',
                'body' => '<blockquote>They understood what we wanted before we could put it into words.</blockquote><p>Ana, owner of a small bakery</p>',
            ], ['width' => 'narrow', 'align' => 'center', 'divider' => 'line'], 'single'],
            ['image_text', [
                'heading' => 'Built to be looked after',
                'body' => '<p>You edit your own pages. Nothing breaks when you do, because every block already knows how to look good.</p>',
                'image_fit' => 'contain',
            ], [], 'image-right'],
            ['hero', [
                'heading' => 'Ready when you are',
                'subheading' => 'Tell us about your project and we will reply within two working days.',
                'cta' => ['label' => 'Get in touch', 'url' => 'mailto:hello@example.com'],
            ], ['surface' => 'contrast', 'divider' => 'slant'], 'left'],
        ],
    ],
    [
        'slug' => 'about',
        'title' => 'About',
        'blocks' => [
            ['hero', [
                'heading' => 'About Northwind',
                'subheading' => 'Three people, one room, and a strong opinion about typography.',
            ], ['rhythm' => 'tight'], 'left'],
            ['text', [
                'heading' => 'How we work',
                'body' => '<p>We start by listening. Before anything is drawn, we want to know who visits your site and what they came for.</p><h3>Then we decide</h3><p>Colour, type, space and shape are chosen once, together, and applied everywhere. That is what keeps a site coherent as it grows.</p><ul><li>One conversation about character</li><li>A handful of real decisions</li><li>Pages you can edit yourself</li></ul>',
            ], ['width' => 'wide'], 'columns'],
            ['image_text', [
                'heading' => 'A quiet workshop',
                'body' => '<p>We keep the team small on purpose. You always talk to the people doing the work.</p>',
                'link' => ['label' => 'Our services', 'url' => '/services'],
            ], ['surface' => 'contrast', 'divider' => 'line'], 'image-right'],
            ['text', [
                'heading' => 'Where to find us',
                'body' => '<p>Ilica 1, Zagreb. Coffee is on us. Write to <a href="mailto:hello@example.com">hello@example.com</a> first.</p>',
            ], ['surface' => 'tinted', 'width' => 'narrow', 'align' => 'center', 'divider' => 'curve'], 'single'],
        ],
    ],
    [
        'slug' => 'services',
        'title' => 'Services',
        'blocks' => [
            ['hero', [
                'heading' => 'What we do',
                'subheading' => 'Design, build and care for small websites that are easy to run.',
                'cta' => ['label' => 'Start a project', 'url' => 'mailto:hello@example.com'],
            ], ['surface' => 'tinted', 'rhythm' => 'airy'], 'split'],
            ['image_text', [
                'heading' => 'New sites',
                'body' => '<p>From a single page to a few dozen, in one or more languages. Designed around your content, not a theme.</p>',
            ], [], 'image-left'],
            ['image_text', [
                'heading' => 'Redesigns',
                'body' => '<p>We keep what works, move your content across, and give the whole site one consistent character.</p>',
            ], ['surface' => 'tinted', 'divider' => 'slant'], 'image-right'],
            ['text', [
                'heading' => 'Care plans',
                'body' => '<p>Updates, backups and small changes every month, for a fixed fee. <strong>No surprises on the invoice.</strong></p>',
            ], ['surface' => 'gradient', 'width' => 'full', 'align' => 'center'], 'single'],
        ],
    ],
    [
        'slug' => 'style-guide',
        'title' => 'Style guide',
        'blocks' => [
            ['hero', [
                'heading' => 'Style guide',
                'subheading' => 'Every surface, rhythm, width and edge in one place. Switch the character and watch all of it change.',
            ], ['rhythm' => 'tight', 'align' => 'center'], 'center'],
            ['text', ['heading' => 'Plain surface', 'body' => '<p>Body text with <strong>bold</strong>, <em>italic</em> and <a href="/">a link</a>.</p>'], [], 'single'],
            ['text', ['heading' => 'Tinted surface', 'body' => '<p>Tinted sections separate content without shouting.</p>'], ['surface' => 'tinted', 'divider' => 'line'], 'single'],
            ['text', ['heading' => 'Contrast surface', 'body' => '<p>Text and <a href="/">links</a> switch colour on contrast surfaces.</p>'], ['surface' => 'contrast', 'divider' => 'slant'], 'single'],
            ['text', ['heading' => 'Image surface', 'body' => '<p>Until a picture is chosen, the image surface uses the contrast colours.</p>'], ['surface' => 'image', 'divider' => 'curve'], 'single'],
            ['text', ['heading' => 'Gradient surface', 'body' => '<p>From the main colour to a neighbouring hue.</p>'], ['surface' => 'gradient'], 'single'],
            ['text', ['heading' => 'Tight rhythm, wide', 'body' => '<p>Less space above and below, more across.</p>'], ['rhythm' => 'tight', 'width' => 'wide'], 'columns'],
            ['text', ['heading' => 'Airy rhythm, full width', 'body' => '<p>Room to breathe, edge to edge.</p>'], ['surface' => 'tinted', 'rhythm' => 'airy', 'width' => 'full', 'divider' => 'curve'], 'single'],
        ],
    ],
];
