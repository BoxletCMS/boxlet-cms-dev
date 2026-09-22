<?php

namespace App\Modules\Pages;

use App\Core\Blocks;
use App\Modules\Design\SectionStyle;
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
     * A BLOCK MAY SEND ITS SKELETON INSTEAD OF ITS FIELDS (PLAN.md D-081). A group the
     * author never touched posts its id, the marker _unchanged, and nothing else; its
     * content, style and layout are taken from $stored. The result is the same block this
     * would have returned had the browser sent every field, so nothing downstream — the
     * canvas, a rejected save, the write — needs to know which blocks did that.
     *
     * @param array<int, array{id: int|null, type: string, content: array<string, mixed>|null, style: array<string, string|int|null>, layout: string}> $stored
     *        block id => the block as stored, for this page's blocks
     * @return array{blocks: list<array{id: int|null, type: string, content: array<string, mixed>|null, style: array<string, string|int|null>, layout: string}>, errors: array<string, string>}
     */
    public static function parse(Blocks $registry, mixed $posted, array $stored): array
    {
        $blocks = [];
        $errors = [];
        foreach (is_array($posted) ? $posted : [] as $raw) {
            if (!is_array($raw) || ($raw['_delete'] ?? '') === '1') {
                continue;
            }
            $id = is_string($raw['id'] ?? null) && ctype_digit($raw['id']) ? (int) $raw['id'] : null;
            if ($id !== null && !isset($stored[$id])) {
                $id = null; // not a block of this page: treat it as new
            }
            if (($raw['_unchanged'] ?? '') === '1') {
                // Nothing to restore it from, so there is nothing it can mean. Adding an
                // empty block here would turn a lost id into content the author never wrote.
                if ($id !== null) {
                    $blocks[] = $stored[$id];
                }
                continue;
            }
            $type = $id !== null ? $stored[$id]['type'] : (is_string($raw['type'] ?? null) ? $raw['type'] : '');

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
                'style' => SectionStyle::normalize($raw['style'] ?? null),
                'layout' => $registry->layout($type, $raw['layout'] ?? null),
            ];
        }

        return ['blocks' => $blocks, 'errors' => $errors];
    }

    /**
     * @param list<array{id: int|null, type: string, content: array<string, mixed>|null, style: array<string, string|int|null>, layout: string}> $blocks
     * @return list<array{id: int|null, type: string, content: array<string, mixed>|null, style: array<string, string|int|null>, layout: string}>
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
     * One repeater item moved within its block — D-011's pattern one level down, where the
     * same route serves the drag and the buttons a browser without JavaScript uses.
     *
     * @param list<array{id: int|null, type: string, content: array<string, mixed>|null, style: array<string, string|int|null>, layout: string}> $blocks
     * @return list<array{id: int|null, type: string, content: array<string, mixed>|null, style: array<string, string|int|null>, layout: string}>
     */
    public static function moveItem(Blocks $registry, array $blocks, int $position, string $field, int $item, string $direction): array
    {
        [$content, $declared] = self::repeaterAt($registry, $blocks, $position, $field);
        if ($content === null || $declared === null) {
            return $blocks;
        }
        $items = is_array($content[$field] ?? null) ? array_values($content[$field]) : [];
        $target = $direction === 'up' ? $item - 1 : $item + 1;
        if (!isset($items[$item], $items[$target])) {
            return $blocks;
        }
        [$items[$item], $items[$target]] = [$items[$target], $items[$item]];
        $content[$field] = $items;
        // The WHOLE element back, never an assignment into its 'content' offset: writing
        // through a nested offset of a list narrows that element to the one key written,
        // and the block shape this method promises is lost with it.
        $updated = $blocks[$position];
        $updated['content'] = $content;
        $blocks[$position] = $updated;

        return $blocks;
    }

    /**
     * An empty item appended to a repeater, for the Add button without JavaScript.
     *
     * Refuses past the maximum rather than growing the list and letting the save reject
     * it: the button that cannot do anything should do nothing, not hand back an error
     * for something the editor itself just did.
     *
     * @param list<array{id: int|null, type: string, content: array<string, mixed>|null, style: array<string, string|int|null>, layout: string}> $blocks
     * @return list<array{id: int|null, type: string, content: array<string, mixed>|null, style: array<string, string|int|null>, layout: string}>
     */
    public static function addItem(Blocks $registry, array $blocks, int $position, string $field): array
    {
        [$content, $declared] = self::repeaterAt($registry, $blocks, $position, $field);
        if ($content === null || $declared === null) {
            return $blocks;
        }
        $items = is_array($content[$field] ?? null) ? array_values($content[$field]) : [];
        if (count($items) >= $declared['max']) {
            return $blocks;
        }
        $items[] = Blocks::emptyItem($declared);
        $content[$field] = $items;
        // The whole element back, for the reason given in moveItem().
        $updated = $blocks[$position];
        $updated['content'] = $content;
        $blocks[$position] = $updated;

        return $blocks;
    }

    /**
     * One block's content and the declaration of a repeater field on it, or [null, null]
     * when the position, the block's type or the field name names no repeater.
     *
     * THE FIELD NAME COMES FROM A FORM, so it is a key only once the registry agrees it is
     * one. An action naming a field the block does not declare moves nothing rather than
     * reaching into stored content with whatever was posted.
     *
     * @param list<array{id: int|null, type: string, content: array<string, mixed>|null, style: array<string, string|int|null>, layout: string}> $blocks
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    private static function repeaterAt(Blocks $registry, array $blocks, int $position, string $field): array
    {
        $block = $blocks[$position] ?? null;
        if ($block === null || !is_array($block['content']) || !$registry->has($block['type'])) {
            return [null, null];
        }
        $declared = $registry->get($block['type'])['fields'][$field] ?? null;
        if (!is_array($declared) || ($declared['type'] ?? '') !== 'repeater') {
            return [null, null];
        }

        return [$block['content'], $declared];
    }

    /**
     * @param array<string, mixed> $field
     * @return array{0: mixed, 1: string|null} the cleaned value and an error, if any
     */
    private static function field(array $field, mixed $raw): array
    {
        $required = $field['required'] === true;

        /*
         * A REPEATER IS THE SAME QUESTION, ONCE PER ITEM (PLAN.md O-11).
         *
         * Each item's fields go through this very method, so a media field inside an item
         * is validated as a media field and a richtext field is sanitised per item — no
         * special case, and nothing here has to know which types exist.
         *
         * Over the maximum is REFUSED rather than trimmed, unlike on render: here somebody
         * typed those items, and silently dropping the last one is how an owner loses work
         * without being told. Blocks::normalize() trims instead, because a page must still
         * draw when a definition's maximum shrinks under it.
         */
        if ($field['type'] === 'repeater') {
            $rows = is_array($raw) ? array_values($raw) : [];
            $items = [];
            $itemError = null;
            foreach ($rows as $row) {
                if (($row['_delete'] ?? '') === '1') {
                    continue;
                }
                $item = [];
                foreach ($field['fields'] as $itemName => $itemField) {
                    [$value, $error] = self::field($itemField, is_array($row) ? ($row[$itemName] ?? null) : null);
                    $item[$itemName] = $value;
                    // The first thing wrong, named once: a message per item per field would
                    // bury the block's own errors under a list nobody reads.
                    $itemError ??= $error;
                }
                $items[] = $item;
            }

            if (count($items) > $field['max']) {
                return [array_slice($items, 0, $field['max']), t('pages.field.repeater_max', ['max' => $field['max']])];
            }
            if ($items === [] && $required) {
                return [[], t('pages.field.required')];
            }

            return [$items, $itemError];
        }

        switch ($field['type']) {
            case 'link':
                $label = is_array($raw) ? self::line($raw['label'] ?? null) : '';
                // A bare email or phone number becomes the link it was meant to be (D-039).
                $url = SafeUrl::normalize(is_array($raw) ? self::line($raw['url'] ?? null) : '');
                // A chosen page wins over a typed address (PLAN.md D-034): the address input
                // is hidden while a page is chosen, so whatever it still holds is stale.
                $page = is_array($raw) ? self::line($raw['page'] ?? null) : '';
                if (preg_match('~^[1-9][0-9]{0,9}$~', $page) === 1) {
                    $url = PageLinks::to((int) $page);
                }
                $value = ['label' => $label, 'url' => $url];
                if ($label === '' && $url === '') {
                    return [$value, $required ? t('pages.field.required') : null];
                }
                if ($url === '') {
                    return [$value, t('pages.field.link_url_missing')];
                }
                if (!SafeUrl::isLink($url)) {
                    return [$value, t('pages.field.link_url')];
                }
                // A page supplies its own title when the text is left empty; an address
                // has nothing to say about itself.
                if ($label === '' && PageLinks::reference($url) === null) {
                    return [$value, t('pages.field.link_label')];
                }

                return [$value, null];

            case 'media':
            case 'form':
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
