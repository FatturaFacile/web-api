<?php

declare(strict_types=1);

namespace App\Support\Security;

use DOMDocument;
use InvalidArgumentException;
use SimpleXMLElement;

/**
 * Hardened XML loader that blocks XXE (`<!DOCTYPE` / `<!ENTITY`) declarations
 * and disables external network access via `LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING`.
 */
final class SafeXmlLoader
{
    public const int LIBXML_SAFE_FLAGS = LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING;

    /**
     * Assert that raw XML does not contain any DOCTYPE or ENTITY declarations.
     */
    public static function assertSafeXml(string $xml): void
    {
        if (preg_match('/<!\s*(DOCTYPE|ENTITY)\b/i', $xml) === 1) {
            throw new InvalidArgumentException('Unsafe XML rejected: DOCTYPE and ENTITY declarations are prohibited (XXE guard).');
        }
    }

    public static function loadSimpleXml(string $xml): SimpleXMLElement
    {
        self::assertSafeXml($xml);

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $element = simplexml_load_string($xml, SimpleXMLElement::class, self::LIBXML_SAFE_FLAGS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($element === false) {
            throw new InvalidArgumentException('Malformed or invalid XML payload.');
        }

        return $element;
    }

    public static function loadDom(string $xml): DOMDocument
    {
        self::assertSafeXml($xml);

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $dom = new DOMDocument;
            $dom->resolveExternals = false;
            $dom->substituteEntities = false;
            $loaded = $dom->loadXML($xml, self::LIBXML_SAFE_FLAGS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($loaded === false) {
            throw new InvalidArgumentException('Malformed or invalid XML payload.');
        }

        return $dom;
    }
}
