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
     * What this server accepts, in bytes and as the server states it.
     *
     * The labels are what the installer's requirements screen showed, so a refusal names
     * the same figure the owner was told to raise.
     *
     * @return array{file: int, request: int, fileLabel: string, requestLabel: string}
     */
    public static function limits(): array
    {
        return [
            'file' => Bytes::parse((string) ini_get('upload_max_filesize')),
            'request' => Bytes::parse((string) ini_get('post_max_size')),
            'fileLabel' => (string) ini_get('upload_max_filesize'),
            'requestLabel' => (string) ini_get('post_max_size'),
        ];
    }

    /**
     * Whether PHP threw the whole request away for being too large.
     *
     * An upload past post_max_size does not arrive truncated: PHP discards it entirely, so
     * $_POST and $_FILES are both empty and the only evidence is that the browser said it
     * was sending more than the limit. Without this the screen would say "choose a file",
     * which is both wrong and impossible to act on.
     */
    public static function postWasDiscarded(int $contentLength, bool $hasFiles, bool $hasPost): bool
    {
        $limit = self::limits()['request'];

        return $limit > 0 && $contentLength > $limit && !$hasFiles && !$hasPost;
    }

    /**
     * Why an upload failed, in words, or null when it did not.
     *
     * PHP's own UPLOAD_ERR_* codes are reported here rather than in the controller,
     * because the two size errors have to name the same limits as everything else.
     */
    public static function problem(int $errorCode): ?string
    {
        $limits = self::limits();

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
