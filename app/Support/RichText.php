<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Reduces HTML typed into a richtext field to a fixed whitelist, applied when a page is
 * saved (SPEC §5.3). Elements outside the whitelist are unwrapped, keeping their text;
 * a few are removed together with their content. Only <a href> keeps an attribute, and
 * only with a URL that SafeUrl allows.
 */
final class RichText
{
    /** Allowed elements, each with its allowed attributes. */
    public const ALLOWED = [
        'p' => [],
        'br' => [],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'h2' => [],
        'h3' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'blockquote' => [],
        'a' => ['href'],
    ];

    /** Removed with everything inside them; any other element keeps its text. */
    private const REMOVE_WITH_CONTENT = [
        'script', 'style', 'template', 'iframe', 'object', 'embed', 'svg', 'math',
        'noscript', 'textarea', 'select', 'button', 'form', 'head', 'title',
    ];

    public static function sanitize(string $html): string
    {
        $html = mb_scrub($html, 'UTF-8');
        if (trim($html) === '') {
            return '';
        }

        $document = new DOMDocument();
        $internalErrors = libxml_use_internal_errors(true);
        try {
            $document->loadHTML(
                '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8">'
                . '</head><body>' . $html . '</body></html>',
                LIBXML_NONET,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($internalErrors);
        }

        $body = $document->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return '';
        }
        self::clean($body);

        $output = '';
        foreach ($body->childNodes as $child) {
            $output .= (string) $document->saveHTML($child);
        }

        return trim($output);
    }

    private static function clean(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node instanceof DOMText) {
                continue;
            }
            if (!$node instanceof DOMElement) {
                $parent->removeChild($node); // comments, processing instructions
                continue;
            }

            $tag = strtolower($node->nodeName);
            if (in_array($tag, self::REMOVE_WITH_CONTENT, true)) {
                $parent->removeChild($node);
                continue;
            }
            self::clean($node);

            if (!isset(self::ALLOWED[$tag])) {
                while ($node->firstChild !== null) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);
                continue;
            }

            $attributes = [];
            foreach ($node->attributes as $attribute) {
                $attributes[] = $attribute->nodeName;
            }
            foreach ($attributes as $attribute) {
                if (!in_array($attribute, self::ALLOWED[$tag], true)) {
                    $node->removeAttribute($attribute);
                }
            }
            if ($tag === 'a' && $node->hasAttribute('href') && !SafeUrl::isAllowed($node->getAttribute('href'))) {
                $node->removeAttribute('href');
            }
        }
    }
}
