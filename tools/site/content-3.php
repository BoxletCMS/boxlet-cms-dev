<?php

/**
 * BOXLETCMS.ORG, THE DEMO'S PAGES LIST WHERE IT CAN BE READ (PLAN.md D-274, the owner,
 * 2026-10-15: "može po tvojoj preporuci"). The owner's screenshot of the whole list, English and
 * Croatian, was unreadable in the box beside a section's words. So:
 *
 *   - the picture beside the words (ai-translated, on the home page and on Languages) is again
 *     the cut of the Croatian rows, which can be read there;
 *   - on Languages, "Or let AI translate it" is words alone, and under it the whole list stands
 *     the width of the page, with a caption, and opens larger on a press.
 *
 *   php tools/site/content-3.php <site root> <site address> <folder of the new pictures> [--dry]
 *
 * The folder holds ai-translated-both.png (the whole list) and ai-translated-hr.png (the cut).
 * A second run changes nothing.
 */

$newPictures = [
    'ai-translated-both.png' => 'The demo\'s pages list after AI translation: its 14 English pages and their 14 Croatian versions, subpages under their parents, each with a Croatian address, all published',
];

require __DIR__ . '/kit.php';

// ---- the picture beside the words: the Croatian rows again --------------------------------------
$beside = $db->one("SELECT id, width, height FROM media WHERE filename = 'ai-translated'");
$cut = $shots . '/ai-translated-hr.png';
$size = is_file($cut) ? getimagesize($cut) : false;
if ($beside === null || $size === false) {
    echo "ai-translated: no picture, or no cut to put in it, left\n";
} elseif ((int) $beside['width'] === $size[0] && (int) $beside['height'] === $size[1]) {
    echo "ai-translated: the Croatian rows already\n";
} elseif ($dry) {
    echo "ai-translated: would be replaced with the Croatian rows\n";
} else {
    $id = (int) $beside['id'];
    $tmp = sys_get_temp_dir() . '/boxlet-replace-' . bin2hex(random_bytes(4)) . '.png';
    copy($cut, $tmp);
    $was = $container->get('media_upload')->replace($id, $tmp, 'ai-translated.png');
    $container->get('media_library')->forgetVariants($was);
    for ($i = 0; $i < 20 && !$container->get('media_variants')->generate($id, null)['complete']; $i++) {
    }
    App\Modules\Media\MediaMeta::save($db, $id, $locale, 'The demo\'s Croatian pages after AI translation: 14 of them, subpages under their parents, each with a Croatian address, all published', '');
    App\Modules\Admin\Activity::record($db, 'media', 'replaced', $id, 'ai-translated');
    @unlink($tmp);
    echo "ai-translated: the Croatian rows again\n";
}

// ---- Languages: the words alone, the whole list under them -------------------------------------
$page('features/languages', static function (array $blocks, array $sections) use ($rows): array {
    $at = null;
    $words = null;
    foreach ($blocks as $i => $block) {
        if ($block['type'] === 'image_text' && ($block['content']['heading'] ?? null) === 'Or let AI translate it') {
            $at = $i;
            $words = $block;
        }
    }
    if ($at === null || $words === null) {
        return [$blocks, $sections, []];
    }
    $place = null;
    foreach ($sections as $n => $section) {
        if ($section['key'] === $words['section']) {
            $place = $n;
        }
    }
    if ($place === null) {
        return [$blocks, $sections, []];
    }
    // The words and the picture as two sections of their own, where the one section stood.
    unset($blocks[$at]);
    [$newSections, $newBlocks] = $rows([
        ['blocks' => [['text', ['heading' => 'Or let AI translate it', 'body' => (string) $words['content']['body']], '', []]]],
        ['blocks' => [['picture', [
            'image' => 'pic:ai-translated-both.png',
            'caption' => 'The demo\'s pages list after translation: its 14 English pages, then their Croatian versions, each under its parent and with an address in Croatian.',
        ], 'full', ['shape' => 'natural', 'enlarge' => 'on']]]],
    ], 200);
    array_splice($sections, $place, 1, $newSections);

    return [array_merge(array_values($blocks), $newBlocks), $sections, ['the words alone, the whole list under them, the width of the page']];
});

$finish();
