<?php

namespace App\Modules\Backup;

use RuntimeException;
use ZipArchive;

/**
 * The backups kept in storage/backups/ (PLAN.md D-139): which there are, what each says of
 * itself, and pruning the automatic ones.
 *
 * A backup is named by when it was begun and what for, 2026-09-28-201500-manual, which is
 * also all a name may be: the name reaches a file path, so nothing else is let through.
 */
final class Backups
{
    /** How many backups made before an update or a restore are kept, of each kind. */
    public const KEEP_AUTOMATIC = 3;

    public function __construct(private readonly string $directory)
    {
    }

    public static function validName(string $name): bool
    {
        return preg_match('~^\d{4}-\d{2}-\d{2}-\d{6}-(manual|update|restore)$~', $name) === 1;
    }

    /** The archive's path, or null when $name is not a finished backup here. */
    public function path(string $name): ?string
    {
        $path = $this->directory . '/' . $name . '.zip';

        return self::validName($name) && is_file($path) ? $path : null;
    }

    /**
     * Every finished backup, newest first.
     *
     * @return list<array{name: string, kind: string, size: int, made: string, version: string, readable: bool}>
     */
    public function all(): array
    {
        $found = [];
        foreach (glob($this->directory . '/*.zip') ?: [] as $path) {
            $name = basename($path, '.zip');
            if (!self::validName($name)) {
                continue;
            }
            try {
                $manifest = $this->manifest($name);
                $readable = true;
            } catch (RuntimeException) {
                $manifest = [];
                $readable = false;
            }
            $found[] = [
                'name' => $name,
                'kind' => (string) substr($name, 18),
                'size' => (int) filesize($path),
                'made' => (string) ($manifest['finished'] ?? ''),
                'version' => (string) ($manifest['version'] ?? ''),
                'readable' => $readable,
            ];
        }
        usort($found, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));

        return $found;
    }

    /**
     * What the backup says of itself.
     *
     * @return array{migrations: list<string>, rows: array<string, int>, key?: string, version?: string, finished?: string}
     */
    public function manifest(string $name): array
    {
        $path = $this->path($name) ?? throw new RuntimeException(t('backups.not_found'));
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException(t('backups.unreadable'));
        }
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $zip->close();
        if (!is_array($manifest) || ($manifest['boxlet'] ?? null) !== 1 || !is_array($manifest['migrations'] ?? null) || !is_array($manifest['rows'] ?? null)) {
            throw new RuntimeException(t('backups.unreadable'));
        }
        /** @var array{migrations: list<string>, rows: array<string, int>, key?: string, version?: string, finished?: string} $manifest */

        return $manifest;
    }

    public function delete(string $name): bool
    {
        $path = $this->path($name);

        return $path !== null && unlink($path);
    }

    /**
     * Removes the oldest automatic backups of $kind past KEEP_AUTOMATIC. The owner's own
     * backups are never removed here.
     */
    public function prune(string $kind): void
    {
        if ($kind === 'manual') {
            return;
        }
        $mine = array_values(array_filter($this->all(), static fn (array $b): bool => $b['kind'] === $kind));
        foreach (array_slice($mine, self::KEEP_AUTOMATIC) as $old) {
            $this->delete($old['name']);
        }
    }
}
