<?php

/**
 * BOXLETCMS.ORG, A VIDEO BEFORE IT IS PRESSED (PLAN.md D-277, the owner, 2026-10-16): the page
 * on a website without a cookie banner showed a grey frame with a play button, and its words
 * said "a placeholder or a cover picture", as if a blank frame were what a visitor gets. Boxlet
 * takes the video's own picture by itself when its address is put into the block (D-147,
 * media-picker.js: on a change, when the block has no cover yet), keeps it as a picture of the
 * site, and serves it from the site. Measured on the copy before this was written: the address
 * changed and nothing pressed, the cover arrived ("The video's own picture is the cover now."),
 * and the published page showed it from /m/, with no frame and no request to YouTube.
 *
 *   php tools/site/content-5.php <site root> <site address> <folder of the new pictures> [--dry]
 */

$newPictures = [
    'video-cover.png' => 'A YouTube video on a Boxlet page before it is pressed: the video\'s own picture with a play button, served from the site itself (Big Buck Bunny, © Blender Foundation, CC BY 3.0)',
];

require __DIR__ . '/kit.php';

$body = '<p>Paste a YouTube or Vimeo address into a Video block and Boxlet takes the video\'s own picture by itself: once, on your server, kept in Media as an ordinary picture of your site. Visitors see that picture with a play button, served from your own server, and nothing is asked of YouTube or Vimeo until someone presses play. A YouTube video then comes from youtube-nocookie.com.</p><p>You can choose another cover picture whenever you like. A map shows a placeholder until it is pressed, and can be OpenStreetMap.</p>';

$page('website-without-cookie-banner', static function (array $blocks, array $sections) use ($body, $picture): array {
    $said = [];
    foreach ($blocks as $i => $block) {
        if ($block['type'] === 'image_text' && ($block['content']['heading'] ?? null) === 'Videos and maps on a press' && $block['content']['body'] !== $body) {
            $blocks[$i]['content']['body'] = $body;
            $blocks[$i]['content']['image'] = $picture('video-cover.png');
            $said[] = 'the video\'s own picture, and words that say so';
        }
    }

    return [$blocks, $sections, $said];
});

$finish();
