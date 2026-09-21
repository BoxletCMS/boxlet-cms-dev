<?php

/**
 * The Appearance screen (PLAN.md D-059): what the merged screen itself says.
 *
 * The decisions keep their own words in design.php and the header and footer keep theirs in
 * chrome.php — the screens merged, the vocabulary did not have to. Only what is new here is
 * new: the five tabs, the publishing, and the one line about which language the preview
 * draws.
 */

return [
    'appearance.title' => 'Appearance',
    'appearance.tab.colour' => 'Colour',
    'appearance.tab.type' => 'Type',
    'appearance.tab.shape' => 'Shape',
    'appearance.tab.page' => 'The page',
    'appearance.tab.chrome' => 'Header and footer',
    'appearance.publish' => 'Publish',
    'appearance.published' => 'Published. The site looks like this from now on.',
    'appearance.words_previewed' => 'The preview shows this language.',

    // The toolbar over the picture.
    'appearance.width' => 'Width',
    'appearance.width.desktop' => 'Desktop',
    'appearance.width.tablet' => 'Tablet',
    'appearance.width.phone' => 'Phone',
    'appearance.zoom' => 'Zoom',
    'appearance.zoom.fit' => 'Fit',
    'appearance.compare' => 'Compare',
    'appearance.compare_hint' => 'Hold to see the published site.',
    'appearance.state.published' => 'Published',
    'appearance.state.unpublished' => 'Not published yet',
    'appearance.state.problem' => 'Fix the contrast to publish',
    'appearance.revert' => 'Discard changes',

    // Designs the owner keeps (D-061).
    'appearance.library' => 'Your designs',
    'appearance.library_hint' => 'Keep what is on this screen under a name, and come back to it later. Saving one here does not change the site.',
    'appearance.library.empty' => 'Nothing kept yet. Set the screen the way you want it and save it under a name.',
    'appearance.library.name' => 'Name for this design',
    'appearance.library.save' => 'Keep this design',
    'appearance.library.use' => 'Use this design',
    'appearance.library.delete' => 'Delete',
    'appearance.library.delete_one' => 'Delete “:name”',
    'appearance.library.saved' => 'Kept as “:name”. The site has not changed — press Publish for that.',
    'appearance.library.overwritten' => '“:name” now holds what is on this screen. The site has not changed.',
    'appearance.library.loaded' => '“:name” is on the screen. Press Publish to put it on the site.',
    'appearance.library.deleted' => '“:name” is gone. What is on the screen is untouched.',
    'appearance.library.name_needed' => 'Give the design a name first.',
    'appearance.library.from' => 'From :character',
    'appearance.library.by_hand' => 'Made by hand',
];
