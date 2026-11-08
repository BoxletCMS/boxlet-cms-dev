<?php

/**
 * BOXLETCMS.ORG, THE COOKIE BOX SAID AS ONE (PLAN.md D-301; the owner, 2026-11-08: "možda nije
 * skroz jasan ovaj text da se radi o cookies visitor postavkama"). Privacy's "A box drawn from
 * your design" (content-6.php) named neither cookies nor visitors in its heading; now it does,
 * in the words the owner approved ("može").
 *
 *   php tools/site/content-7.php <site root> <site address> <folder of the new pictures> [--dry]
 */

$newPictures = [];

require __DIR__ . '/kit.php';

$heading = 'A cookie consent box for your visitors';
$body = '<p>When a script needs consent, your visitors see a box that asks which cookies they allow. It takes your site\'s typefaces, colours and corners, so it looks like part of the site. <strong>Reject all</strong> is as easy as Accept all, and visitors can allow statistics and marketing separately. They can change their answer at any time under <strong>Cookie settings</strong> at the bottom of every page. Without such a script, the box never appears.</p>';

$page('features/privacy', static function (array $blocks, array $sections) use ($heading, $body): array {
    foreach ($blocks as $i => $block) {
        if (($block['content']['heading'] ?? null) === 'A box drawn from your design') {
            $blocks[$i]['content']['heading'] = $heading;
            $blocks[$i]['content']['body'] = $body;

            return [$blocks, $sections, ['the cookie consent box, said plainly']];
        }
    }

    return [$blocks, $sections, []];
});

$finish();
