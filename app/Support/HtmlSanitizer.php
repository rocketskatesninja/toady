<?php

namespace App\Support;

use DOMElement;
use DOMNode;

/**
 * Minimal allow-list HTML sanitiser for the owner-authored campaign-email composer. Keeps basic
 * formatting, drops scripts/styles/iframes and any inline event handlers or javascript:/data: URLs.
 * Not a full sanitiser, but it closes the obvious XSS vectors in this one owner-only HTML pipeline.
 */
class HtmlSanitizer
{
    /** @var list<string> */
    private const ALLOWED_TAGS = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 's', 'a', 'ul', 'ol', 'li', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'span', 'div'];

    /** @var list<string> */
    private const ALLOWED_ATTRS = ['href', 'title', 'target', 'rel'];

    public static function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        // the xml PI forces UTF-8; everything we keep is read back out of <body>
        $doc->loadHTML('<?xml encoding="utf-8"?><body>'.$html.'</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_use_internal_errors($prev);

        $body = $doc->getElementsByTagName('body')->item(0);
        if (! $body) {
            return '';
        }

        self::scrub($body);

        $out = '';
        foreach (iterator_to_array($body->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    private static function scrub(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                continue; // text nodes are kept verbatim (saveHTML escapes them)
            }

            $tag = strtolower($child->tagName);

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                if (in_array($tag, ['script', 'style'], true)) {
                    $child->parentNode->removeChild($child); // drop the tag AND its contents
                } else {
                    self::scrub($child); // clean children, then unwrap: keep the text, drop the tag
                    while ($child->firstChild) {
                        $child->parentNode->insertBefore($child->firstChild, $child);
                    }
                    $child->parentNode->removeChild($child);
                }

                continue;
            }

            foreach (iterator_to_array($child->attributes) as $attr) {
                $name = strtolower($attr->name);
                $dangerousUrl = in_array($name, ['href', 'src'], true)
                    && preg_match('/^\s*(javascript|data|vbscript):/i', $attr->value);
                if (! in_array($name, self::ALLOWED_ATTRS, true) || $dangerousUrl) {
                    $child->removeAttribute($attr->name);
                }
            }

            if ($tag === 'a' && $child->getAttribute('target') === '_blank') {
                $child->setAttribute('rel', 'noopener noreferrer');
            }

            self::scrub($child);
        }
    }
}
