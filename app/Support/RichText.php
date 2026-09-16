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
        'h4' => [],
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
     * sits below it. h5 and deeper collapse to h4, the deepest we store (PLAN.md D-016),
     * which matters for pasted documents rather than for Trix.
     */
    private const RENAME = [
        'div' => 'p',
        'h1' => 'h2',
        'h5' => 'h4',
        'h6' => 'h4',
    ];

    /** A p may not contain these, so a div holding one is unwrapped rather than renamed. */
    private const BLOCK = ['p', 'div', 'ul', 'ol', 'li', 'blockquote', 'h2', 'h3', 'h4', 'table'];

    /** Blocks whose leading and trailing <br> are dropped on save (PLAN.md D-014). */
    private const TRIM_BREAKS = ['p', 'h2', 'h3', 'h4', 'li', 'blockquote'];

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

            // Last, so the block's final name is known: a div has already become a p.
            if (in_array($tag, self::TRIM_BREAKS, true)) {
                self::trimBreaks($node);
            }

            if ($tag === 'li') {
                self::unwrapLoneParagraph($node);
            }
        }
    }

    /**
     * A paragraph that is the only block in a list item is the editor's packaging, not the
     * author's structure, so it is unwrapped.
     *
     * TipTap's schema puts a paragraph inside every list item, so <li>one</li> came back as
     * <li><p>one</p></li> the first time a field was edited. Measured on the front end:
     * that list grew from 51px to 67px, because a paragraph inside a list item takes the
     * normal paragraph margin and gains 16px above and below every item. Storage keeps one
     * shape whichever editor produced it.
     *
     * Only a lone wrapper goes. An item holding two paragraphs keeps both, because that is
     * something the author made rather than something the editor added. An item holding a
     * paragraph beside a nested list keeps it too: the paragraph is then not the only block
     * in the item.
     *
     * This removes a wrapper and allows nothing new, so the whitelist is unchanged.
     */
    private static function unwrapLoneParagraph(DOMElement $item): void
    {
        $blocks = [];
        foreach ($item->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $blocks[] = $child;
            } elseif ($child instanceof DOMText && trim($child->textContent) !== '') {
                return; // text beside the paragraph: the item is not just a wrapper
            }
        }
        if (count($blocks) !== 1 || strtolower($blocks[0]->nodeName) !== 'p') {
            return;
        }

        $paragraph = $blocks[0];
        while ($paragraph->firstChild !== null) {
            $item->insertBefore($paragraph->firstChild, $paragraph);
        }
        $item->removeChild($paragraph);
    }

    /**
     * Drops <br> at the very start and end of a block.
     *
     * Given <p>alpha</p>, Trix hands back <div><br>alpha<br><br></div>. Without this rule
     * every open-and-save of a page added a break at each end of every rich text field on
     * it — including fields nobody edited — and it compounded with each cycle, so text
     * drifted further from what was written every time the page was opened. Measured in a
     * browser, not inferred.
     *
     * The cost is a deliberate break at the very edge of a paragraph. In Trix a blank line
     * is a new paragraph, so nothing a person can type is lost with it.
     */
    private static function trimBreaks(DOMElement $node): void
    {
        foreach ([true, false] as $fromStart) {
            while (true) {
                $child = $fromStart ? $node->firstChild : $node->lastChild;
                // Whitespace between the edge and the break is left where it is; only the
                // break itself goes.
                while ($child instanceof DOMText && trim($child->textContent) === '') {
                    $child = $fromStart ? $child->nextSibling : $child->previousSibling;
                }
                if (!$child instanceof DOMElement || strtolower($child->nodeName) !== 'br') {
                    break;
                }
                $node->removeChild($child);
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
