<?php

/*
 * WHAT THE DEMO'S PICTURES ARE ASKED FOR (The Printworks, a community arts centre in a former
 * printing works): per folder, how many, from which sources in order, and with which searches.
 * build.php reads this; nothing here runs.
 *
 * A folder's 'sources' are its steps, in order: a later step only fills what an earlier one
 * left. A step is a source's name, or [source, take (at most this many), queries (its own,
 * else the folder's), orientation (Pixabay's: horizontal for the first page, where heroes
 * come from)]. Pexels is asked only where PEXELS_API_KEY exists; Pixabay needs PIXABAY_API_KEY.
 *
 * Shapes: about 60% landscape, 25% portrait, 15% square, the heroes needing wide pictures.
 */

$twentieth = ['abstract', 'composition', 'Bauhaus', 'Constructivism', 'type specimen', 'alphabet', 'poster'];

return [
    'user_agent' => 'BoxletDemo/0.1 (+https://github.com/BoxletCMS/boxlet-cms-dev)',
    // The longer side a picture must have; Pixabay's own where its key gives no larger file.
    'min_side' => 1600,
    'categories' => [
        'space' => [
            // D-210: the owner removed one and asked for no other; 24.
            'target' => 24,
            // The review's pictures, by id: a search's order (Pixabay's changes daily) never
            // swaps one out; a search fills only what review removed.
            'sources' => [['source' => 'pixabay', 'orientation' => 'horizontal', 'prefer' => [
                'pixabay:1566019', 'pixabay:1629208', 'pixabay:1845174', 'pixabay:2093264', 'pixabay:2181960',
                'pixabay:2181980', 'pixabay:2359436', 'pixabay:2650392', 'pixabay:2660095', 'pixabay:3331374',
                'pixabay:3569701', 'pixabay:3613563', 'pixabay:3665281', 'pixabay:4755891', 'pixabay:484596',
                'pixabay:4955328', 'pixabay:5574630', 'pixabay:6517488', 'pixabay:6610493',
                'pixabay:6651873', 'pixabay:6790786', 'pixabay:6875824', 'pixabay:7283947', 'pixabay:8573855',
            ]], 'pexels', 'openverse'],
            'queries' => [
                'old printing works interior', 'industrial loft event space', 'brick warehouse gallery',
                'empty exhibition hall', 'concrete staircase daylight', 'workshop room tables',
                'cafe interior plants', 'courtyard string lights', 'building facade brick', 'large windows industrial',
            ],
        ],
        'art' => [
            // Twenty (D-210): the eighteen, then one more abstract and one more typographic, so the
            // twenty hold four abstract or geometric works and two typographic ones.
            'target' => 20,
            // The owner, D-209: the range of tones and colours over the count. Fourteen from The
            // Met, six with the twentieth century's terms. Those were to be AIC's, but its IIIF
            // images sit behind a Cloudflare challenge this server cannot pass (measured: 403,
            // cf-mitigated: challenge), so The Met is asked them first and AIC after.
            'sources' => [
                // The fourteen the review kept, by source id; the search fills only what fails.
                ['source' => 'met', 'take' => 14, 'prefer' => [
                    'met:51088', 'met:399123', 'met:436305', 'met:433565', 'met:697703', 'met:10996', 'met:827660',
                    'met:54333', 'met:45434', 'met:436450', 'met:54135', 'met:662220', 'met:12025', 'met:11225',
                ]],
                ['source' => 'met', 'take' => 4, 'queries' => $twentieth, 'prefer' => ['met:335009', 'met:700316', 'met:824642', 'met:824219']],
                // D-210: AIC was asked for these four, and its images are still behind the
                // challenge (403, 2026-10-09), so The Met is asked first and AIC after.
                ['source' => 'met', 'take' => 1, 'queries' => ['geometric', 'abstract', 'Bauhaus', 'Constructivism'], 'prefer' => ['met:823738']],
                ['source' => 'met', 'take' => 1, 'queries' => ['type specimen', 'typography', 'lettering', 'alphabet'], 'prefer' => ['met:373134']],
                ['source' => 'aic', 'take' => 4, 'queries' => $twentieth],
            ],
            'queries' => [
                'woodblock print', 'poster', 'botanical illustration', 'abstract composition',
                'still life', 'type specimen',
            ],
        ],
        'food' => [
            'target' => 6,
            // The review's pictures, by id: a search's order (Pixabay's changes daily) never
            // swaps one out; a search fills only what review removed.
            'sources' => [['source' => 'pixabay', 'prefer' => [
                'pixabay:1868181', 'pixabay:4280208', 'pixabay:6171744', 'pixabay:7292250', 'pixabay:791045',
                'pixabay:986784',
            ]], 'pexels'],
            'queries' => ['coffee cup table top', 'pastries counter', 'flat white overhead', 'sandwich board cafe'],
        ],
        'books' => [
            'target' => 5,
            // The review's pictures, by id: a search's order (Pixabay's changes daily) never
            // swaps one out; a search fills only what review removed.
            'sources' => [['source' => 'pixabay', 'prefer' => [
                'pixabay:1842261', 'pixabay:1868070', 'pixabay:378600', 'pixabay:8934573', 'pixabay:1163695',
            ]], 'pexels'],
            // D-210: a bookshop with no cover to read.
            'queries' => ['bookshop', 'bookstore shelves', 'bookshop interior'],
        ],
        'workshop' => [
            // D-210: six the review kept, two more from the owner's three searches.
            'target' => 8,
            // The seven the review kept, by id: no people, the printing house's paper among them.
            'sources' => [['source' => 'pixabay', 'orientation' => 'horizontal', 'prefer' => [
                'pixabay:2345477', 'pixabay:1139098', 'pixabay:8026824', 'pixabay:414936',
                'pixabay:3733104', 'pixabay:5814817', 'pixabay:4026195', 'pixabay:849346',
            ]], 'pexels'],
            // The two found by 'linocut' and 'screen print': the brief's words gave a ball bearing and
            // a typewriter.
            'queries' => ['ink roller', 'screen printing', 'hands setting type', 'linocut', 'screen print'],
        ],
        'archive' => [
            'target' => 5,
            // The review's pictures, by id: a search's order (Pixabay's changes daily) never
            // swaps one out; a search fills only what review removed.
            'sources' => [['source' => 'met', 'prefer' => [
                'met:400164', 'met:659683', 'met:659890', 'met:817468', 'met:819478',
            ]], 'openverse'],
            'queries' => ['printing press', 'typesetting', 'print shop', 'compositor type case', 'printing office'],
        ],
        'texture' => [
            'target' => 5,
            // The review's pictures, by id: a search's order (Pixabay's changes daily) never
            // swaps one out; a search fills only what review removed.
            'sources' => [['source' => 'pixabay', 'orientation' => 'horizontal', 'prefer' => [
                'pixabay:1747649', 'pixabay:1807376', 'pixabay:1845394', 'pixabay:2804900', 'pixabay:626781',
            ]], 'openverse'],
            // D-210: paper grain or concrete.
            'queries' => ['paper texture', 'paper grain', 'concrete texture', 'crumpled paper'],
        ],
    ],
];
