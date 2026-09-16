<?php

namespace App\Modules\Pages;

use App\Core\Blocks;
use App\Core\View;
use App\Modules\Design\Composition;
use RuntimeException;

/**
 * The picture of a block in the library.
 *
 * Previews are rendered from the block itself, never drawn by hand. A hand-made
 * thumbnail stops matching its block the first time the block changes and nobody
 * notices for months; a rendered one cannot lie.
 *
 * Each is a complete little page written to public/cache/previews, so the library loads
 * them as static files and no request touches PHP. The file name carries a hash of the
 * block definition and of the site's compiled stylesheet, so changing either the block
 * or the design produces a new name and the old file is removed.
 */
final class BlockPreview
{
    private const DIRECTORY = 'previews';

    /**
     * The preview file for one block type, generated if it is not already there.
     *
     * @param string $stylesheet the site's compiled tokens file name, so a design change
     *                           regenerates every preview
     */
    public static function file(Blocks $registry, string $type, string $stylesheet, string $cacheDirectory): string
    {
        $definition = $registry->get($type);
        $hash = substr(hash('sha256', json_encode($definition, JSON_THROW_ON_ERROR) . '|' . $stylesheet), 0, 12);
        $file = $type . '.' . $hash . '.html';
        $directory = $cacheDirectory . '/' . self::DIRECTORY;
        $target = $directory . '/' . $file;

        if (is_file($target)) {
            return $file;
        }

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create directory {$directory}");
        }
        self::write($target, self::document($registry, $type));
        foreach (glob($directory . '/' . $type . '.*.html') ?: [] as $old) {
            if (basename($old) !== $file) {
                unlink($old);
            }
        }

        return $file;
    }

    /**
     * Every block type's preview file, keyed by type.
     *
     * @return array<string, string>
     */
    public static function all(Blocks $registry, string $stylesheet, string $cacheDirectory): array
    {
        $files = [];
        foreach ($registry->types() as $type) {
            $files[$type] = self::file($registry, $type, $stylesheet, $cacheDirectory);
        }

        return $files;
    }

    /**
     * Sample content for a block, built from its own field types so a block added later
     * gets a preview without anyone writing one.
     *
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    public static function sample(array $definition): array
    {
        $content = [];
        foreach ($definition['fields'] as $name => $field) {
            $content[$name] = match ($field['type']) {
                'media' => 1,
                'link' => ['label' => t('preview.link'), 'url' => '#'],
                'select' => $field['options'][0],
                'richtext' => '<p>' . e(t('preview.body')) . '</p>',
                'textarea' => t('preview.body'),
                default => t('preview.heading'),
            };
        }

        return $content;
    }

    private static function document(Blocks $registry, string $type): string
    {
        $definition = $registry->get($type);
        $html = $registry->render(
            $type,
            self::sample($definition),
            // Neutral section defaults on purpose: a preview shows what the block is, not
            // where on a page it might land. What ties it to this site is the compiled
            // stylesheet it links, which is also what its file name is hashed against.
            Composition::style(null, $type),
            (string) $definition['defaults']['layout'],
        );

        return (new View(__DIR__ . '/views'))->render('admin/preview', 'en', [
            'title' => t('block.' . $type),
            'blockHtml' => $html,
        ], null);
    }

    private static function write(string $target, string $contents): void
    {
        $temporary = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($temporary, $contents) === false || !rename($temporary, $target)) {
            throw new RuntimeException("Cannot write {$target}");
        }
    }
}
