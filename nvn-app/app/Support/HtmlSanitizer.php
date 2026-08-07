<?php

namespace App\Support;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Reduces a lump of HTML to an allowlist of tags and attributes.
 *
 * Blog articles are the only place in this application where HTML written by a
 * person is rendered to visitors unescaped, and two of the ways in are not
 * obvious. One is a compromised or careless admin account, which turns a blog
 * post into stored XSS against every reader, including the next admin to open
 * the panel. The other is the WordPress import: fifteen years of posts written
 * through half a dozen editors and page builders, carrying inline event
 * handlers and embed code nobody has looked at since.
 *
 * So the rule is an allowlist, not a blocklist. Anything not named here is
 * removed, which means a new attack does not need a new rule to be blocked.
 *
 * This is not a substitute for escaping. It exists because escaping is exactly
 * what a blog post cannot have — the markup is the content. Everywhere else in
 * this application, use {{ }} and let Blade escape it.
 */
class HtmlSanitizer
{
    /** Tags kept, with their permitted attributes. */
    private const ALLOWED = [
        'p' => [], 'br' => [], 'hr' => [],
        'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [],
        'ul' => [], 'ol' => ['start'], 'li' => [],
        'blockquote' => ['cite'], 'pre' => [], 'code' => [],
        'a' => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'title', 'width', 'height', 'loading'],
        'figure' => [], 'figcaption' => [],
        'table' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [],
        'tr' => [], 'th' => ['colspan', 'rowspan'], 'td' => ['colspan', 'rowspan'],
        'span' => [], 'div' => [], 'small' => [], 'sub' => [], 'sup' => [],
    ];

    /**
     * Removed outright, contents and all.
     *
     * Everything else that is not on the allowlist is unwrapped instead — its
     * tag goes and its text stays — because an unknown wrapper around a
     * paragraph should cost you the wrapper, not the paragraph. These carry no
     * readable text worth keeping, and <style> and <form> in particular do real
     * damage: one repaints the page, the other posts a visitor's typing
     * somewhere of the author's choosing.
     */
    private const STRIPPED = [
        'script', 'style', 'iframe', 'object', 'embed', 'applet',
        'form', 'input', 'button', 'select', 'textarea', 'option',
        'link', 'meta', 'base', 'noscript', 'svg', 'math',
    ];

    public static function clean(?string $html): ?string
    {
        $html = trim((string) $html);

        if ($html === '') {
            return null;
        }

        $document = new DOMDocument();

        // Tell libxml the string is UTF-8. Without this it assumes Latin-1 and
        // mangles every accented character and typographic quote in the post.
        $previous = libxml_use_internal_errors(true);

        $document->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);

        // Comments can hide markup that some downstream editor will resurrect,
        // and conditional comments are executable in their own right. The
        // processing instruction is the UTF-8 hint prepended above: libxml
        // keeps it as a node, and without this it is written back out at the
        // top of every article.
        foreach (iterator_to_array($xpath->query('//comment()|//processing-instruction()') ?: []) as $node) {
            $node->parentNode?->removeChild($node);
        }

        foreach (self::STRIPPED as $tag) {
            foreach (iterator_to_array($document->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        // Snapshot before mutating: unwrapping rewrites the tree underneath a
        // live NodeList and nodes get skipped.
        $elements = iterator_to_array($document->getElementsByTagName('*'));

        foreach ($elements as $element) {
            // parentNode, not isConnected: DOMNode::$isConnected arrived in PHP
            // 8.3, and on 8.2 reading it yields null with a warning — which
            // made this guard skip every element and the whole sanitiser a
            // no-op that still looked correct.
            if (! $element instanceof DOMElement || $element->parentNode === null) {
                continue;
            }

            $tag = strtolower($element->nodeName);

            if (! array_key_exists($tag, self::ALLOWED)) {
                self::unwrap($element);
                continue;
            }

            self::cleanAttributes($element, self::ALLOWED[$tag]);
        }

        $out = trim($document->saveHTML() ?: '');

        return $out === '' ? null : $out;
    }

    /** Replace an element with its own children, keeping the text. */
    private static function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if (! $parent) {
            return;
        }

        while ($element->firstChild) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    private static function cleanAttributes(DOMElement $element, array $allowed): void
    {
        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            /** @var DOMAttr $attribute */
            $name = strtolower($attribute->nodeName);

            if (! in_array($name, $allowed, true)) {
                $element->removeAttribute($attribute->nodeName);
                continue;
            }

            if (in_array($name, ['href', 'src'], true)
                && ! self::urlIsSafe($attribute->nodeValue)) {
                $element->removeAttribute($attribute->nodeName);
            }
        }

        // A link that opens a new tab hands the opener to the destination
        // unless it is told not to. Authors will not remember; this does.
        if (strtolower($element->nodeName) === 'a' && $element->getAttribute('target') !== '') {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    /**
     * Only http, https, mailto, tel, an in-page anchor, a site-relative path,
     * or an inline image.
     *
     * Everything else — javascript:, data:text/html, vbscript:, and the
     * whitespace-and-entity tricks used to disguise them — is dropped. Note the
     * check is on what is left after control characters are removed, because
     * "java\0script:" is a URL browsers have historically been willing to run.
     */
    private static function urlIsSafe(?string $url): bool
    {
        $url = strtolower(trim(preg_replace('/[\x00-\x20]|&#[xX]?0*(9|a|d|10|13);?/i', '', (string) $url)));

        if ($url === '') {
            return false;
        }

        if (str_starts_with($url, '#') || str_starts_with($url, '/')) {
            return true;
        }

        if (str_starts_with($url, 'data:image/')) {
            return ! str_contains($url, 'svg'); // SVG carries script.
        }

        foreach (['http://', 'https://', 'mailto:', 'tel:'] as $scheme) {
            if (str_starts_with($url, $scheme)) {
                return true;
            }
        }

        // No scheme at all — a relative path like "about/team". Safe.
        return ! preg_match('/^[a-z0-9.+-]*:/', $url);
    }
}
