<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Tax;

use DOMDocument;
use Modules\GeneralLedger\Exceptions\VatSettlementException;

/**
 * Checks a LIPE file against the structural profile in resources/xsd (not the official schema: see the XSD header).
 */
final class LipeXsdValidator
{
    /**
     * @return list<string> the validation errors, empty when the document is valid
     */
    public static function errors(string $xml): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            if (! $document->loadXML($xml)) {
                return ['The LIPE document is not well-formed XML.'];
            }

            $document->schemaValidate(dirname(__DIR__, 3).'/resources/xsd/lipe-ivp18-profile.xsd');

            return array_map(static fn ($error): string => trim($error->message), libxml_get_errors());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public static function assertValid(string $xml): void
    {
        $errors = self::errors($xml);

        if ($errors !== []) {
            throw VatSettlementException::invalid('The LIPE file does not match the schema: '.implode('; ', $errors));
        }
    }
}
