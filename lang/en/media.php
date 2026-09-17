<?php

// Pictures: uploading, refusing, and what the variants are for (SPEC §5.5).
//
// Split from en.php, which had grown past the 300-line rule. t() loads every file in
// lang/, so a new one needs no registration — and a key defined twice in two files is a
// test failure rather than whichever file happened to load first.

return [
    // Media (SPEC §5.5). What a refusal says has to name the thing that was wrong:
    // "invalid file" sends someone back to try the same file again.
    'media.refused' => 'That file is not a picture Boxlet accepts (it looks like :type). Use a JPEG, PNG, WebP, GIF or AVIF.',
    'media.refused_avif' => 'This server cannot read AVIF pictures. Save it as a JPEG or PNG and upload that.',
    'media.refused_heic' => 'HEIC pictures are not accepted, because most servers cannot read them. Export it as a JPEG first — on an iPhone, Settings → Camera → Formats → Most Compatible.',
    'media.storage_unwritable' => 'The picture could not be saved: storage/uploads is not writable. Nothing was changed.',
    'media.too_large' => 'That picture is larger than this server accepts (:limit per file). Nothing was uploaded.',
    'media.too_large_request' => 'The upload was cut off because it is larger than this server accepts (:limit per request). Nothing was uploaded.',
    'media.incomplete_upload' => 'The upload did not finish — the connection dropped part-way. Nothing was saved; try again.',
    'media.no_file' => 'No file was chosen.',
];
