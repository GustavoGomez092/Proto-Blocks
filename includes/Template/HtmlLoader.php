<?php
/**
 * HTML Loader - parse UTF-8 HTML fragments into DOMDocument on every libxml
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Template;

use DOMDocument;

/**
 * DOMDocument::loadHTML() needs to be told the input is UTF-8: libxml before
 * 2.14 otherwise reads it as ISO-8859-1 and mangles every non-ASCII
 * character. The old fix prefixed '<?xml encoding="UTF-8"?>', but that leaves
 * a node behind that saveHTML() writes back out, and its type depends on the
 * libxml version: a processing instruction up to 2.13, a bogus COMMENT
 * ('<!--?xml encoding="UTF-8"?-->') from 2.14, whose HTML5 tokenizer treats
 * '<?' as the start of a comment. A strip that only knew the first form put a
 * stray comment in front of every block on newer Linux hosts.
 *
 * load() needs no hint at all: every non-ASCII character is handed to libxml
 * as a numeric character reference, so the input is pure ASCII and decodes
 * identically whatever charset libxml assumes. References are decoded by the
 * parser in text and attribute values, so the DOM holds the same characters
 * as before. (Inside <script>/<style> raw text they stay literal references;
 * Renderer's entity decoder restores those on output, as it already did for
 * the references libxml's own serialiser emits there.)
 */
final class HtmlLoader
{
    /** Parse $html into $dom as a fragment: no implied html/body, no doctype. */
    public static function load(DOMDocument $dom, string $html): void
    {
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML(self::toAscii($html), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        self::stripXmlDeclarations($dom);
    }

    /**
     * Remove top-level XML declarations in either form libxml produces: the
     * processing instruction (libxml < 2.14) and the comment '?xml ...?'
     * (libxml >= 2.14). load() never injects one; this keeps a template whose
     * markup itself starts with an XML prolog (a pasted SVG file, say) from
     * printing it inside <body>.
     */
    public static function stripXmlDeclarations(DOMDocument $dom): void
    {
        // Snapshot first: removing from a live DOMNodeList skips siblings.
        foreach (iterator_to_array($dom->childNodes) as $node) {
            $isPi = XML_PI_NODE === $node->nodeType;
            $isCommentForm = XML_COMMENT_NODE === $node->nodeType
                && 1 === preg_match('/^\?xml[\s?]/i', (string) $node->nodeValue);
            if ($isPi || $isCommentForm) {
                $dom->removeChild($node);
            }
        }
    }

    /** $html with every non-ASCII character as a numeric character reference. */
    private static function toAscii(string $html): string
    {
        $encode = static function (array $m): string {
            $bytes = array_values((array) unpack('C*', $m[0]));
            $code = match (count($bytes)) {
                2 => (($bytes[0] & 0x1F) << 6) | ($bytes[1] & 0x3F),
                3 => (($bytes[0] & 0x0F) << 12) | (($bytes[1] & 0x3F) << 6) | ($bytes[2] & 0x3F),
                default => (($bytes[0] & 0x07) << 18) | (($bytes[1] & 0x3F) << 12) | (($bytes[2] & 0x3F) << 6) | ($bytes[3] & 0x3F),
            };

            return '&#' . $code . ';';
        };

        $ascii = preg_replace_callback('/[\x{80}-\x{10FFFF}]/u', $encode, $html);
        if ($ascii !== null) {
            return $ascii;
        }

        // Invalid UTF-8 makes /u fail: replace bad sequences with U+FFFD
        // (htmlspecialchars' ENT_SUBSTITUTE, reversed exactly by the decode)
        // and encode again.
        $flags = ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5;
        $scrubbed = htmlspecialchars_decode(htmlspecialchars($html, $flags, 'UTF-8'), ENT_NOQUOTES | ENT_HTML5);

        return preg_replace_callback('/[\x{80}-\x{10FFFF}]/u', $encode, $scrubbed) ?? '';
    }
}
