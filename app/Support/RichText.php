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
        // An attachment's insides are the editor's markup, not the author's words.
        'figure',
    ];

    /**
     * Elements renamed to their nearest allowed equivalent instead of being unwrapped.
     *
     * Trix wraps every block in a div and offers a single heading level, which it emits
     * as h1 (SPEC §5.3). Unwrapping those would throw away the structure the author made:
     * paragraphs would run together and every heading would become bare text. Renaming
     * keeps the meaning and lands it inside the whitelist.
     *
     * h1 becomes h2 because the page's own title is the h1; a heading inside body copy
     * sits below it. h4 and deeper collapse to h3, the deepest we allow, which matters for
     * pasted documents rather than for Trix.
     */
    private const RENAME = [
        'div' => 'p',
        'h1' => 'h2',
        'h4' => 'h3',
        'h5' => 'h3',
        'h6' => 'h3',
    ];

    /** A p may not contain these, so a div holding one is unwrapped rather than renamed. */
    private const BLOCK = ['p', 'div', 'ul', 'ol', 'li', 'blockquote', 'h2', 'h3', 'table'];

    /** Trix's attachments carry JSON in these; they are its one proprietary format. */
    private const ATTACHMENT_ATTRIBUTES = [
        'data-trix-attachment', 'data-trix-attributes', 'data-trix-content-type',
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
            if (in_array($tag, self::REMOVE_WITH_CONTENT, true) || self::isAttachment($node)) {
                $parent->removeChild($node);
                continue;
            }
            self::clean($node);

            // After the children are cleaned, so a nested div has already become a p or
            // been unwrapped and the block test below sees the final shape.
            if (isset(self::RENAME[$tag]) && ($tag !== 'div' || !self::hasBlockChild($node))) {
                $node = self::rename($parent, $node, self::RENAME[$tag]);
                $tag = strtolower($node->nodeName);
            }

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

    /**
     * Attachments are disabled in the editor itself; this is the backstop, so a later
     * version of it cannot reintroduce them silently.
     */
    private static function isAttachment(DOMElement $node): bool
    {
        foreach (self::ATTACHMENT_ATTRIBUTES as $attribute) {
            if ($node->hasAttribute($attribute)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when this element holds something a paragraph may not contain, which is what
     * decides whether a div is renamed to p or unwrapped. Renaming regardless would put a
     * list inside a paragraph — invalid markup that we would have produced ourselves.
     */
    private static function hasBlockChild(DOMElement $node): bool
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->nodeName), self::BLOCK, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The same element under another name, keeping its children and its place.
     */
    private static function rename(DOMNode $parent, DOMElement $node, string $name): DOMElement
    {
        $document = $node->ownerDocument;
        if ($document === null) {
            return $node;
        }
        $replacement = $document->createElement($name);
        while ($node->firstChild !== null) {
            $replacement->appendChild($node->firstChild);
        }
        $parent->replaceChild($replacement, $node);

        return $replacement;
    }
}
