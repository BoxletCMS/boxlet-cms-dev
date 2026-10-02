<?php

/*
 * THE DEMO SITE (README 1.6, PLAN.md D-167): Atelier Lumen, an interior studio. Its home page
 * is the page the builder's mockup shows, with the mockup's words where it has them; four
 * short pages behind it; and one more, unlisted, that shows every block in every layout.
 *
 * In English, which Boxlet ships in, with the home page translated into Croatian (README 1.6);
 * a site whose first language is Croatian gets the two the other way round. A function of the
 * language rather than a list, so one definition makes both — they can only differ in words.
 *
 * Each page is SECTIONS, as the builder makes them: a section's own style (only the keys the
 * page sets; every other is the character's, D-165), its layout, and its blocks with the column
 * each stands in. A block is [type, content, layout, options, column].
 *
 * Links are `demo:{key}`, a page by its key below, and become page references on the way in
 * (D-034): a renamed page keeps its links. The seed references no picture (the media note in
 * DemoSite); an empty picture is the placeholder an owner sees before choosing one.
 *
 * @return list<array{key: string, slug: string, title: string, description: string, menu: bool, sections: list<array{style: array<string, string>, layout: string, blocks: list<array{string, array<string, mixed>, string, array<string, string>, int}>}>}>
 */
return static function (string $lang): array {
    $t = static fn (string $hr, string $en): string => $lang === 'hr' ? $hr : $en;
    $p = static fn (string $hr, string $en): string => '<p>' . $t($hr, $en) . '</p>';

    return [
        [
            'key' => 'home',
            'slug' => '',
            'title' => $t('Početna', 'Home'),
            'description' => $t('Studio za interijere u Zagrebu — stanovi, uredi i trgovine.', 'An interior studio in Zagreb — flats, offices and shops.'),
            'menu' => false,
            'sections' => [
                ['style' => ['name' => $t('Uvod', 'Intro'), 'surface' => 'tinted', 'pad_top' => '120', 'pad_bottom' => '120', 'animation' => 'fade'], 'layout' => 'one', 'blocks' => [
                    ['hero', [
                        'heading' => $t('Prostori koji izgledaju kao da ste ih oduvijek imali', 'Spaces that feel like they were always yours'),
                        'subheading' => $t('Projektiramo stanove, urede i male trgovine — od prve skice do zadnje police.', 'We design flats, offices and small shops — from the first sketch to the last shelf.'),
                        'cta' => ['label' => $t('Dogovorite konzultacije', 'Book a consultation'), 'url' => 'demo:contact'],
                        'image' => 'demo-picture:hero-living-room',
                    ], '', [], 0],
                ]],
                ['style' => ['name' => $t('Usluge', 'Services'), 'anchor' => $t('usluge', 'services')], 'layout' => 'one', 'blocks' => [
                    ['cards', [
                        'heading' => $t('Što radimo', 'What we do'),
                        'items' => [
                            ['heading' => $t('Stanovi', 'Homes'), 'body' => $p('Preuređenje od jedne sobe do cijelog stana.', 'From a single room to the whole flat.'), 'image' => 'demo-picture:card-homes'],
                            ['heading' => $t('Uredi', 'Offices'), 'body' => $p('Radni prostori za male timove.', 'Workspaces for small teams.'), 'image' => 'demo-picture:card-offices'],
                            ['heading' => $t('Trgovine', 'Shops'), 'body' => $p('Izlozi, police i put kupca.', 'Windows, shelving and the customer\'s path.'), 'image' => 'demo-picture:card-shops'],
                        ],
                    ], 'grid', [], 0],
                ]],
                ['style' => ['name' => $t('Proces', 'Process')], 'layout' => 'one', 'blocks' => [
                    ['image_text', [
                        'heading' => $t('Od skice do ključa', 'From sketch to keys'),
                        'body' => $p('Vodimo cijeli projekt: mjerenje, 3D prikaz, izbor materijala i nadzor izvođača.', 'We run the whole project: measuring, 3D views, choosing materials and overseeing the builders.'),
                        'link' => ['label' => $t('Kako radimo →', 'How we work →'), 'url' => 'demo:process'],
                        'image' => 'demo-picture:process-plan',
                    ], 'image-left', [], 0],
                ]],
                ['style' => ['name' => $t('Iskustvo', 'Experience')], 'layout' => 'wide-left', 'blocks' => [
                    ['text', [
                        'heading' => $t('Zašto mi', 'Why us'),
                        'body' => $p('Petnaest godina iskustva i preko dvjesto prostora. Radimo malo projekata odjednom, pa svaki dobije punu pažnju.', 'Fifteen years and more than two hundred spaces. We take on only a few projects at a time, so each one gets our full attention.'),
                    ], 'single', [], 0],
                    ['quote', [
                        'quote' => $t('„Stan je postao dom u šest tjedana, bez ijednog dana kašnjenja.”', '“Our flat became a home in six weeks, without a single day\'s delay.”'),
                        'attribution' => 'Marija K., Zagreb',
                    ], 'plain', [], 1],
                ]],
                ['style' => ['name' => $t('Kontakt', 'Contact'), 'surface' => 'contrast'], 'layout' => 'one', 'blocks' => [
                    ['cta', [
                        'heading' => $t('Spremni za početak?', 'Ready to start?'),
                        'body' => $t('Javite se i dogovorimo prvi korak.', 'Get in touch and we\'ll plan the first step.'),
                        'action' => ['label' => $t('Kontakt', 'Contact'), 'url' => 'demo:contact'],
                    ], 'banner', [], 0],
                ]],
            ],
        ],
        [
            'key' => 'about',
            'slug' => $t('o-nama', 'about'),
            'title' => $t('O nama', 'About'),
            'description' => $t('Mali studio, petnaest godina, preko dvjesto prostora.', 'A small studio, fifteen years, more than two hundred spaces.'),
            'menu' => true,
            'sections' => [
                ['style' => [], 'layout' => 'one', 'blocks' => [
                    ['hero', [
                        'heading' => $t('Mali studio, velika pažnja', 'A small studio, close attention'),
                        'subheading' => $t('Troje arhitekata i jedna radionica u Zagrebu.', 'Three architects and a workshop in Zagreb.'),
                    ], 'left', ['height' => 'auto'], 0],
                ]],
                ['style' => [], 'layout' => 'one', 'blocks' => [
                    ['image_text', [
                        'heading' => $t('Kako smo počeli', 'How we began'),
                        'body' => $p('Studio je nastao 2010. iz jedne narudžbe: kuhinje za prijatelje kojoj nitko nije znao naći mjesto. Od tada radimo isto, samo više puta.', 'The studio began in 2010 with one job: a kitchen for friends that nobody could find room for. We have been doing the same ever since, only more often.'),
                        'image' => 'demo-picture:about-studio',
                    ], 'image-right', [], 0],
                ]],
                ['style' => [], 'layout' => 'one', 'blocks' => [
                    ['stats', [
                        'heading' => $t('U brojkama', 'In numbers'),
                        'items' => [
                            ['value' => '15', 'label' => $t('godina', 'years')],
                            ['value' => '200+', 'label' => $t('prostora', 'spaces')],
                            ['value' => '3', 'label' => $t('arhitekta', 'architects')],
                        ],
                    ], 'three', [], 0],
                ]],
            ],
        ],
        [
            'key' => 'services',
            'slug' => $t('usluge', 'services'),
            'title' => $t('Usluge', 'Services'),
            'description' => $t('Stanovi, uredi i trgovine: što radimo i kako se dogovaramo.', 'Homes, offices and shops: what we do and how we agree it.'),
            'menu' => true,
            'sections' => [
                ['style' => [], 'layout' => 'one', 'blocks' => [
                    ['text', [
                        'heading' => $t('Usluge', 'Services'),
                        'body' => $p('Svaki projekt počinje razgovorom i mjerenjem. Nakon toga dobivate prijedlog s cijenom, pa tek onda crtamo.', 'Every project starts with a conversation and a measurement. Then you get a proposal with a price, and only then do we draw.'),
                    ], 'single', [], 0],
                ]],
                ['style' => [], 'layout' => 'one', 'blocks' => [
                    ['cards', [
                        'items' => [
                            ['heading' => $t('Stanovi', 'Homes'), 'body' => $p('Od jedne sobe do cijelog stana, s nadzorom radova.', 'From one room to a whole flat, with the works overseen.')],
                            ['heading' => $t('Uredi', 'Offices'), 'body' => $p('Radna mjesta, sobe za sastanke i mjesto za kavu.', 'Desks, meeting rooms and somewhere for coffee.')],
                            ['heading' => $t('Trgovine', 'Shops'), 'body' => $p('Izlog, police, rasvjeta i put kupca kroz prostor.', 'Window, shelving, light and the customer’s path through the room.')],
                        ],
                    ], 'list', [], 0],
                ]],
                ['style' => [], 'layout' => 'one', 'blocks' => [
                    ['accordion', [
                        'heading' => $t('Česta pitanja', 'Questions'),
                        'items' => [
                            ['question' => $t('Koliko traje projekt?', 'How long does a project take?'), 'answer' => $p('Stan obično šest do deset tjedana, od prvog razgovora do ključa.', 'A flat usually takes six to ten weeks, from the first talk to the keys.')],
                            ['question' => $t('Radite li izvan Zagreba?', 'Do you work outside Zagreb?'), 'answer' => $p('Da, u krugu od sto kilometara.', 'Yes, within a hundred kilometres.')],
                        ],
                        'start' => 'first-open',
                    ], 'list', [], 0],
                ]],
            ],
        ],
        [
            'key' => 'process',
            'slug' => $t('kako-radimo', 'how-we-work'),
            'title' => $t('Kako radimo', 'How we work'),
            'description' => $t('Od prvog razgovora do ključa, korak po korak.', 'From the first talk to the keys, step by step.'),
            'menu' => true,
            'sections' => [
                ['style' => [], 'layout' => 'one', 'blocks' => [
                    ['text', [
                        'heading' => $t('Kako radimo', 'How we work'),
                        'body' => '<p>' . $t('Svaki prostor prolazi isti put. Nije brz, ali nitko na njemu ne čeka.', 'Every space goes the same way. It is not fast, but nobody on it waits.') . '</p>'
                            . '<h2>' . $t('Mjerenje', 'Measuring') . '</h2>' . $p('Dolazimo, mjerimo i slušamo. Prvi sat je besplatan.', 'We come, measure and listen. The first hour is free.')
                            . '<h2>' . $t('Prijedlog', 'Proposal') . '</h2>' . $p('U tjedan dana dobivate tlocrt, dva smjera i cijenu.', 'Within a week you have a plan, two directions and a price.')
                            . '<h2>' . $t('Izvedba', 'Building') . '</h2>' . $p('Biramo izvođače s kojima radimo godinama i dolazimo na gradilište svaki tjedan.', 'We choose builders we have worked with for years and visit the site every week.'),
                    ], 'single', [], 0],
                ]],
                ['style' => [], 'layout' => 'one', 'blocks' => [
                    ['quote', [
                        'quote' => $t('„Znali smo svaki tjedan što se radi i zašto.”', '“Every week we knew what was being done and why.”'),
                        'attribution' => 'Tomislav B.',
                        'role' => $t('vlasnik trgovine', 'shop owner'),
                    ], 'big', [], 0],
                ]],
            ],
        ],
        [
            'key' => 'contact',
            'slug' => $t('kontakt', 'contact'),
            'title' => $t('Kontakt', 'Contact'),
            'description' => $t('Pišite nam ili nazovite — odgovaramo isti dan.', 'Write or call — we answer the same day.'),
            'menu' => true,
            'sections' => [
                ['style' => [], 'layout' => 'one', 'blocks' => [
                    ['form', [
                        'heading' => $t('Pišite nam', 'Write to us'),
                        'intro' => $t('Recite nam nešto o prostoru i javit ćemo se isti dan.', 'Tell us a little about the space and we will answer the same day.'),
                        'form' => 'demo:form',
                    ], 'beside', [], 0],
                ]],
                ['style' => [], 'layout' => 'halves', 'blocks' => [
                    ['text', ['heading' => $t('Studio', 'The studio'), 'body' => $p('Ilica 42, Zagreb', 'Ilica 42, Zagreb')], 'single', [], 0],
                    ['text', ['heading' => $t('Radno vrijeme', 'Hours'), 'body' => $p('Pon–pet, 9–17 h', 'Mon–Fri, 9 am–5 pm')], 'single', [], 1],
                ]],
            ],
        ],
        (require __DIR__ . '/blocks.php')($t, $p),
    ];
};
