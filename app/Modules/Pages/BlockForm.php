<?php

namespace App\Modules\Pages;

use App\Core\Blocks;
use App\Support\RichText;
use App\Support\SafeUrl;

/**
 * Reads the page editor's blocks[n][field] input. All validation lives here, on the
 * server; the browser only sends fields in the order they appear in the form.
 */
final class BlockForm
{
    /**
     * Blocks in submitted order, with every value cleaned for its field type, and errors
     * keyed "position.field". A block marked _delete is left out. An existing block keeps
     * its stored type whatever the form claims. A layout the block does not declare
     * falls back to its default rather than being stored.
     *
     * @param array<int, string> $storedTypes block id => type, for this page's blocks
     * @return array{blocks: list<array{id: int|null, type: string, content: array<string, mixed>|null, style: array<string, string>, layout: string}>, errors: array<string, string>}
     */
    public static function parse(Blocks $registry, mixed $posted, array $storedTypes): array
    {
        $blocks = [];
        $errors = [];
        foreach (is_array($posted) ? $posted : [] as $raw) {
            if (!is_array($raw) || ($raw['_delete'] ?? '') === '1') {
                continue;
            }
            $id = is_string($raw['id'] ?? null) && ctype_digit($raw['id']) ? (int) $raw['id'] : null;
            if ($id !== null && !isset($storedTypes[$id])) {
                $id = null; // not a block of this page: treat it as new
            }
            $type = $id !== null ? $storedTypes[$id] : (is_string($raw['type'] ?? null) ? $raw['type'] : '');

            if (!$registry->has($type)) {
                if ($id !== null) {
                    $blocks[] = ['id' => $id, 'type' => $type, 'content' => null, 'style' => [], 'layout' => ''];
                }
                continue;
            }

            $position = count($blocks);
            $content = [];
            foreach ($registry->get($type)['fields'] as $name => $field) {
                [$value, $error] = self::field($field, $raw[$name] ?? null);
                $content[$name] = $value;
                if ($error !== null) {
                    $errors["{$position}.{$name}"] = $error;
                }
            }
            $blocks[] = [
                'id' => $id,
                'type' => $type,
                'content' => $content,
                'style' => [],
                'layout' => $registry->layout($type, $raw['layout'] ?? null),
            ];
        }

        return ['blocks' => $blocks, 'errors' => $errors];
    }

    /**
     * @param list<array{id: int|null, type: string, content: array<string, mixed>|null, style: array<string, string>, layout: string}> $blocks
     * @return list<array{id: int|null, type: string, content: array<string, mixed>|null, style: array<string, string>, layout: string}>
     */
    public static function move(array $blocks, int $position, string $direction): array
    {
        $target = $direction === 'up' ? $position - 1 : $position + 1;
        if (isset($blocks[$position], $blocks[$target])) {
            [$blocks[$position], $blocks[$target]] = [$blocks[$target], $blocks[$position]];
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed> $field
     * @return array{0: mixed, 1: string|null} the cleaned value and an error, if any
     */
    private static function field(array $field, mixed $raw): array
    {
        $required = $field['required'] === true;

        switch ($field['type']) {
            case 'link':
                $label = is_array($raw) ? self::line($raw['label'] ?? null) : '';
                $url = is_array($raw) ? self::line($raw['url'] ?? null) : '';
                $value = ['label' => $label, 'url' => $url];
                if ($label === '' && $url === '') {
                    return [$value, $required ? t('pages.field.required') : null];
                }
                if ($url === '') {
                    return [$value, t('pages.field.link_url_missing')];
                }
                if (!SafeUrl::isAllowed($url)) {
                    return [$value, t('pages.field.link_url')];
                }

                return [$value, $label === '' ? t('pages.field.link_label') : null];

            case 'media':
                $text = self::line($raw);
                if ($text === '') {
                    return [null, $required ? t('pages.field.required') : null];
                }

                return ctype_digit($text) && (int) $text > 0 ? [(int) $text, null] : [null, t('pages.field.media')];

            case 'select':
                $options = $field['options'];
                if (is_string($raw) && in_array($raw, $options, true)) {
                    return [$raw, null];
                }

                return [$options[0], t('pages.field.select')];

            case 'richtext':
                $html = RichText::sanitize(is_string($raw) ? $raw : '');
                $empty = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) === '';

                return [$empty ? '' : $html, $empty && $required ? t('pages.field.required') : null];

            case 'textarea':
                $text = is_string($raw) ? trim(self::printable(str_replace("\r\n", "\n", $raw))) : '';

                return [$text, $text === '' && $required ? t('pages.field.required') : null];

            default: // text
                $text = self::line($raw);

                return [$text, $text === '' && $required ? t('pages.field.required') : null];
        }
    }

    /**
     * A single-line string: valid UTF-8, no control characters, no line breaks.
     */
    private static function line(mixed $raw): string
    {
        return is_string($raw) ? trim(self::printable(str_replace(["\r", "\n"], ' ', $raw))) : '';
    }

    private static function printable(string $text): string
    {
        return (string) preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~', '', mb_scrub($text, 'UTF-8'));
    }
}
