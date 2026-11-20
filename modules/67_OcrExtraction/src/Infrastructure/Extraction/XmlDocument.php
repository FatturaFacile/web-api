<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Infrastructure\Extraction;

use SimpleXMLElement;

/**
 * Loads an XML invoice without its namespaces, so the extractors address elements by their local names whatever
 * prefixes the sender used (`ram:`, `rsm:`, `p:`, none). External entities are never loaded.
 */
final class XmlDocument
{
    public static function load(string $xml): ?SimpleXMLElement
    {
        $xml = preg_replace('/\sxmlns(?::\w+)?\s*=\s*("[^"]*"|\'[^\']*\')/', '', $xml) ?? $xml;
        $xml = preg_replace('/(<\/?)[\w.-]+:([\w.-]+)/', '$1$2', $xml) ?? $xml;
        $xml = preg_replace('/(\s)[\w.-]+:([\w.-]+\s*=)/', '$1$2', $xml) ?? $xml;

        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document === false ? null : $document;
    }

    /**
     * Text of the first node matching an XPath relative to a node, null when absent or blank.
     */
    public static function text(SimpleXMLElement $node, string $path): ?string
    {
        $found = $node->xpath($path);

        if (! is_array($found) || $found === []) {
            return null;
        }

        $text = trim((string) $found[0]);

        return $text === '' ? null : $text;
    }
}
