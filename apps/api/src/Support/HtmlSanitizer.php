<?php

declare(strict_types=1);

namespace Central\Api\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * A tight allowlist sanitizer for the small amount of rich text the forum
 * accepts — bold, lists, links, quotes and code.
 *
 * The web client already renders posts through Angular's own sanitizer, so
 * the browser is safe regardless. This is defence in depth for every other
 * consumer of a post's body — the mobile app, notification digests, exports —
 * none of which get Angular's guarantees. The rule is conservative: anything
 * not on the allowlist is unwrapped (its text kept) or, for genuinely unsafe
 * elements, dropped whole.
 */
final class HtmlSanitizer
{
    /** Tags that survive, mapped to the attributes each may keep. */
    private const ALLOWED = [
        'p' => [], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [],
        'u' => [], 's' => [], 'strike' => [], 'ul' => [], 'ol' => [], 'li' => [],
        'blockquote' => [], 'code' => [], 'pre' => [], 'h3' => [], 'h4' => [],
        'a' => ['href'],
    ];

    /** Elements dropped with all their contents — never merely unwrapped. */
    private const STRIP_WHOLE = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'svg', 'math'];

    public function clean(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $doc = new DOMDocument('1.0', 'UTF-8');

        $previous = libxml_use_internal_errors(true);
        // Wrap so the fragment parses as body content, and force UTF-8.
        $doc->loadHTML(
            '<?xml encoding="UTF-8"?><div id="__root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('__root');
        if (!$root instanceof DOMElement) {
            return '';
        }

        $this->scrub($root, $doc);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    private function scrub(DOMNode $node, DOMDocument $doc): void
    {
        // Snapshot children first: the loop mutates the live child list.
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (!$child instanceof DOMElement) {
                // Text and the like stay as-is (DOM escapes on output).
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::STRIP_WHOLE, true)) {
                $node->removeChild($child);
                continue;
            }

            // Recurse before deciding, so an unwrapped node's safe children survive.
            $this->scrub($child, $doc);

            if (!array_key_exists($tag, self::ALLOWED)) {
                $this->unwrap($child);
                continue;
            }

            $this->scrubAttributes($child, $tag);
        }
    }

    /** Replaces an element with its children, keeping the text it held. */
    private function unwrap(DOMElement $el): void
    {
        $parent = $el->parentNode;
        if ($parent === null) {
            return;
        }
        while ($el->firstChild !== null) {
            $parent->insertBefore($el->firstChild, $el);
        }
        $parent->removeChild($el);
    }

    private function scrubAttributes(DOMElement $el, string $tag): void
    {
        $keep = self::ALLOWED[$tag];

        foreach (iterator_to_array($el->attributes ?? []) as $attr) {
            $name = strtolower($attr->nodeName);

            if (!in_array($name, $keep, true)) {
                $el->removeAttribute($attr->nodeName);
                continue;
            }

            // Every attribute on the allowlist is URL-bearing (href), so a
            // surviving one must carry a safe scheme or it goes too.
            if (!$this->safeUrl($attr->nodeValue ?? '')) {
                $el->removeAttribute($attr->nodeName);
            }
        }

        // Links open safely in a new tab and never leak the opener.
        if ($tag === 'a' && $el->hasAttribute('href')) {
            $el->setAttribute('rel', 'noopener nofollow ugc');
            $el->setAttribute('target', '_blank');
        }
    }

    private function safeUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        if (str_starts_with($url, '#') || str_starts_with($url, '/')) {
            return true;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https', 'mailto'], true);
    }
}
