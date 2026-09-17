<?php

namespace App\Modules\Media;

use App\Core\Db;
use App\Modules\Pages\Slug;
use App\Support\Bytes;
use RuntimeException;

/**
 * Accepting an uploaded picture (SPEC §6).
 *
 * Three things decide whether a file is allowed, and all three must agree:
 *
 *   what it CLAIMS     the browser's filename extension — never trusted, only used to
 *                      reject early and to pick the stored extension once the sniff agrees
 *   what it IS         finfo reading the bytes, which is the only opinion that counts
 *   what we ALLOW      a closed list of formats, so a new one is a decision rather than
 *                      an accident
 *
 * The stored filename is generated here, never taken from the client: a name arriving as
 * "photo.php.jpg", "../../evil" or 300 bytes of Unicode is a name this never has to
 * reason about, because it is discarded.
 *
 * HEIC is refused deliberately, even where the server could read it. Most shared hosts
 * cannot, and an upload that works for the developer and fails for the customer is worse
 * than one that always says "convert it first".
 */
final class MediaUpload
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

    public function __construct(
        private readonly Db $db,
        private readonly string $storagePath,
        private readonly MediaEncoder $encoder,
    ) {
    }

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
     * Why an upload failed, in words, or null when it did not.
     *
     * PHP's own UPLOAD_ERR_* codes are reported here rather than in the controller,
     * because the two size errors have to name the same limits as everything else. The
     * limits themselves live in Bytes: the router needs them before any of this runs, to
     * tell a discarded post from an expired form.
     */
    public static function problem(int $errorCode): ?string
    {
        $limits = Bytes::limits();

        return match ($errorCode) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_INI_SIZE => t('media.too_large', ['limit' => $limits['fileLabel']]),
            UPLOAD_ERR_FORM_SIZE => t('media.too_large', ['limit' => $limits['fileLabel']]),
            UPLOAD_ERR_PARTIAL => t('media.incomplete_upload'),
            UPLOAD_ERR_NO_FILE => t('media.no_file'),
            default => t('media.storage_unwritable'),
        };
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

    /**
     * The name a picture is stored under: derived from what the person called it, so the
     * library is searchable, but generated rather than accepted.
     */
    public static function filenameFor(string $originalName): string
    {
        $base = Slug::fromTitle(pathinfo($originalName, PATHINFO_FILENAME));
        if ($base === '') {
            $base = 'image';
        }

        return substr($base, 0, 80);
    }

    /**
     * The row for a file already stored with these bytes, or null.
     *
     * @return array<string, mixed>|null
     */
    public function existing(string $hash): ?array
    {
        return $this->db->one('SELECT * FROM media WHERE hash = ?', [$hash]);
    }

    /**
     * Stores the original and records it, or returns the existing row for identical bytes.
     *
     * The original is never modified and never public: it goes to storage/uploads/, which
     * is outside the web root, and only generated variants are served (D-020).
     *
     * @return array{id: int, duplicate: bool}
     */
    public function store(string $temporaryFile, string $originalName): array
    {
        $sniffed = self::sniff($temporaryFile);
        $extension = self::extensionFor($originalName, $sniffed);
        if ($extension === null) {
            throw new RuntimeException(t('media.refused', ['type' => $sniffed === '' ? '?' : $sniffed]));
        }
        // A host with neither encoder is real shared hosting. inspect() would still succeed
        // — getimagesize is core, not GD — so without this the upload APPEARS to work: the
        // original is stored, a row is written, and generation then makes nothing at all.
        // The library shows a card with no thumbnail and nothing says why.
        if ($this->encoder->driver() === null) {
            throw new RuntimeException(t('media.refused_no_encoder'));
        }
        if ($extension === 'avif' && !$this->encoder->supports('avif')) {
            throw new RuntimeException(t('media.refused_avif'));
        }

        $hash = (string) sha1_file($temporaryFile);
        $existing = $this->existing($hash);
        if ($existing !== null) {
            return ['id' => (int) $existing['id'], 'duplicate' => true];
        }

        $info = $this->encoder->inspect($temporaryFile);
        $filename = self::filenameFor($originalName);
        $relative = 'uploads/' . $hash . '.' . $extension;
        $target = $this->storagePath . '/' . $relative;

        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(t('media.storage_unwritable'));
        }
        if (!self::place($temporaryFile, $target)) {
            throw new RuntimeException(t('media.storage_unwritable'));
        }

        $this->db->query(
            'INSERT INTO media (filename, original_name, path, mime, size, width, height, hash, created_at, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $filename,
                substr($originalName, 0, 255),
                $relative,
                $info['mime'],
                (int) filesize($target),
                $info['width'],
                $info['height'],
                $hash,
                gmdate('Y-m-d H:i:s'),
                'incomplete',
            ],
        );

        return ['id' => (int) $this->db->lastInsertId(), 'duplicate' => false];
    }

    /**
     * Puts different bytes behind an existing picture, keeping its id and its library name.
     *
     * The id is the whole point: every page showing this picture shows the new one, with
     * no page edited and nothing to go and find. The library name is kept too — it is
     * what the owner called this picture, not a property of the bytes.
     *
     * EVERYTHING THAT CAN REFUSE DOES SO BEFORE ANYTHING IS WRITTEN OR REMOVED, so a
     * rejected replacement leaves the picture exactly as it was.
     *
     * @return array<string, mixed> the row as it stood, so the caller can remove what was
     *                              generated from the old bytes
     */
    public function replace(int $mediaId, string $temporaryFile, string $originalName): array
    {
        $existing = $this->db->one('SELECT * FROM media WHERE id = ?', [$mediaId]);
        if ($existing === null) {
            throw new RuntimeException(t('media.not_found'));
        }

        $sniffed = self::sniff($temporaryFile);
        $extension = self::extensionFor($originalName, $sniffed);
        if ($extension === null) {
            throw new RuntimeException(t('media.refused', ['type' => $sniffed === '' ? '?' : $sniffed]));
        }
        // Here too, and for a worse reason: replace() clears variants_json and deletes the
        // old original, so accepting bytes this server cannot process would destroy a
        // working picture and put an unusable one in its place. Refused before anything is
        // written or removed, like every other refusal in this method.
        if ($this->encoder->driver() === null) {
            throw new RuntimeException(t('media.refused_no_encoder'));
        }
        if ($extension === 'avif' && !$this->encoder->supports('avif')) {
            throw new RuntimeException(t('media.refused_avif'));
        }

        $hash = (string) sha1_file($temporaryFile);
        $duplicate = $this->existing($hash);
        if ($duplicate !== null && (int) $duplicate['id'] !== $mediaId) {
            // hash is unique, so this would fail at the database anyway. Naming the
            // picture that already holds those bytes is more use than a constraint error.
            throw new RuntimeException(t('media.replace_duplicate', ['name' => (string) $duplicate['filename']]));
        }

        $info = $this->encoder->inspect($temporaryFile);
        $relative = 'uploads/' . $hash . '.' . $extension;
        $target = $this->storagePath . '/' . $relative;
        if (!self::place($temporaryFile, $target)) {
            throw new RuntimeException(t('media.storage_unwritable'));
        }

        $this->db->query(
            'UPDATE media SET original_name = ?, path = ?, mime = ?, size = ?, width = ?, height = ?,
                    hash = ?, variants_json = NULL, status = ? WHERE id = ?',
            [
                substr($originalName, 0, 255),
                $relative,
                $info['mime'],
                (int) filesize($target),
                $info['width'],
                $info['height'],
                $hash,
                'incomplete',
                $mediaId,
            ],
        );

        // The old original is nothing's source now. Removed after the row points at the
        // new one, so a failure halfway leaves one file too many rather than a row whose
        // original has gone.
        $old = $this->storagePath . '/' . (string) $existing['path'];
        if ($old !== $target && is_file($old)) {
            @unlink($old);
        }

        return $existing;
    }

    /**
     * move_uploaded_file for a real upload, rename for anything else — so tests and the
     * development photograph script use the same path as a browser does.
     */
    private static function place(string $from, string $to): bool
    {
        if (is_uploaded_file($from)) {
            return move_uploaded_file($from, $to);
        }

        return @copy($from, $to);
    }
}
