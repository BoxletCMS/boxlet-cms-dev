<?php

// Pictures: uploading, refusing, the library screen, and what one picture means
// (SPEC §5.5).
//
// Split from en.php, which had grown past the 300-line rule. t() loads every file in
// lang/, so a new one needs no registration — and a key defined twice in two files is a
// test failure rather than whichever file happened to load first.

return [
    // What a refusal says has to name the thing that was wrong: "invalid file" sends
    // someone back to try the same file again.
    'media.refused' => 'That file is not a picture Boxlet accepts (it looks like :type). Use a JPEG, PNG, WebP, GIF or AVIF.',
    'media.refused_avif' => 'This server cannot read AVIF pictures. Save it as a JPEG or PNG and upload that.',
    'media.refused_heic' => 'HEIC pictures are not accepted, because most servers cannot read them. Export it as a JPEG first — on an iPhone, Settings → Camera → Formats → Most Compatible.',
    'media.storage_unwritable' => 'The picture could not be saved: storage/uploads is not writable. Nothing was changed.',
    'media.too_large' => 'That picture is larger than this server accepts (:limit per file). Nothing was uploaded.',
    'media.incomplete_upload' => 'The upload did not finish — the connection dropped part-way. Nothing was saved; try again.',
    'media.no_file' => 'No file was chosen.',

    // Said in the browser, before anything is sent. A body over the server's limit never
    // reaches a page of ours, so this is the only place it can be explained.
    'media.too_large_named' => ':name is :size, which is more than this server accepts (:limit per picture). It was not uploaded.',
    'media.too_large_total' => 'Those pictures come to :size together, and this server accepts :limit per upload. Send them in smaller batches.',
    'media.limits' => 'Up to :file per picture, and :request in one go.',

    // The library screen.
    'media.title' => 'Pictures',
    'media.empty' => 'No pictures yet. Upload one to start.',
    'media.upload' => 'Upload pictures',
    'media.upload_hint' => 'Choose files, or drop them here. JPEG, PNG, WebP, GIF or AVIF.',
    'media.upload_submit' => 'Upload',
    'media.uploaded' => 'Uploaded :count.',
    'media.duplicate' => ':name was already in the library, so it was not stored twice.',
    'media.search' => 'Search by name',
    'media.search_submit' => 'Search',
    'media.search_none' => 'No picture matches “:term”.',
    'media.no_thumb' => 'Still being processed',
    'media.incomplete' => 'Not all sizes are made yet.',
    'media.finish' => 'Finish processing',
    'media.finished' => 'Finished :name.',
    'media.dimensions' => ':width × :height',
    'media.not_found' => 'That picture no longer exists.',
    // The picker says why rather than showing an empty panel, which would read as
    // "there are no pictures" when the truth is that the list could not be fetched.
    'media.pick_failed' => 'The pictures could not be loaded. Close this and try again.',
    // The picker's resting state has to say what pressing it does. "office" alone read as
    // a text field somebody had typed into.
    'media.pick_choose' => 'Choose picture',
    'media.pick_change' => 'Change',
    'media.pick_close' => 'Close',

    // One picture.
    'media.back' => 'All pictures',
    'media.details' => 'Details',
    'media.original_name' => 'Uploaded as',
    'media.dimensions_label' => 'Dimensions',
    'media.size' => 'File size',
    'media.format' => 'Format',
    'media.added' => 'Added',
    'media.used_by' => 'Used on',
    'media.used_by_none' => 'No page uses this picture yet.',
    'media.meaning' => 'What this picture shows',
    'media.save' => 'Save',
    'media.delete' => 'Delete',
    'media.delete_confirm' => 'Delete :name? This cannot be undone.',
    'media.deleted' => 'The picture was deleted.',
    // A refusal that does not say WHICH pages sends someone hunting through the site.
    'media.in_use' => 'That picture is still used on :pages. Remove it there first, then delete it.',
    'media.replace' => 'Replace',
    'media.replace_hint' => 'Put a different picture in its place. Every page using it shows the new one, and the sizes are made again.',
    'media.replaced' => 'The picture was replaced.',
    'media.replace_duplicate' => 'Those exact bytes are already in the library, as :name. Nothing was replaced.',
    'media.focal' => 'Focal point',
    'media.focal_hint' => 'Click the picture to choose what stays in frame when it is cropped.',
    'media.focal_x' => 'Across (%)',
    'media.focal_y' => 'Down (%)',
    'media.focal_save' => 'Save focal point',
    'media.focal_saved' => 'The focal point was moved, and the cropped sizes were made again.',
    'media.alt' => 'Alt text',
    'media.alt_hint' => 'What the picture shows, for someone who cannot see it. Leave it empty if the picture is decoration.',
    'media.caption' => 'Caption',
    'media.meta_saved' => 'Saved.',
];
