<?php

namespace App\Modules\Media;

/**
 * Whether a file may be uploaded at all, and what it may be stored as (SPEC §6).
 *
 * Three things decide, and all three must agree:
 *
 *   what it CLAIMS     the browser's filename extension — never trusted, only used to
 *                      reject early and to pick the stored extension once the sniff agrees
 *   what it IS         finfo reading the bytes, which is the only opinion that counts
 *   what we ALLOW      a closed list of formats, so a new one is a decision rather than
 *                      an accident
 *
 * HEIC is refused deliberately, even where the server could read it. Most shared hosts
 * cannot, and an upload that works for the developer and fails for the customer is worse
 * than one that always says "convert it first".
 *
 * Split from MediaUpload, which reached the 300-line rule when suggested alt text (D-025)
 * added two lines to it. The seam is a real one and not a line count: this answers what
 * may come in, while MediaUpload puts it somewhere and records it. Nothing here touches a
 * database or the filesystem beyond reading the bytes it is asked about.
 */
final class MediaFileType
{
    /** Extension => the MIME finfo must agree on. */
    private const ALLOWED = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'avif' => 'image/avif',
    ];

    /**
     * Refused by name whatever the bytes say. finfo would catch a real script anyway, but
     * a file called .php that reached a servable directory through some future path is a
     * class of accident worth refusing twice.
     */
    private const NEVER = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps',
        'cgi', 'pl', 'py', 'jsp', 'asp', 'aspx', 'sh', 'bash', 'htaccess', 'htm', 'html', 'svg'];

    /**
     * The extension a file may be stored under, or null when it is not allowed.
     *
     * $claimed is the client's filename; $sniffed is what finfo says about the bytes.
     */
    public static function extensionFor(string $claimed, string $sniffed): ?string
    {
        $extension = strtolower(pathinfo($claimed, PATHINFO_EXTENSION));
        if ($extension === '' || in_array($extension, self::NEVER, true)) {
            return null;
        }
        // A double extension is judged on its last part, which is what a web server would
        // do — and the sniff below has to agree anyway.
        if (!isset(self::ALLOWED[$extension])) {
            return null;
        }
        if (self::ALLOWED[$extension] !== $sniffed) {
            return null;
        }

        return $extension === 'jpeg' ? 'jpg' : $extension;
    }

    /**
     * What finfo makes of a file's bytes.
     */
    public static function sniff(string $file): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file);

        return is_string($mime) ? $mime : '';
    }
}
