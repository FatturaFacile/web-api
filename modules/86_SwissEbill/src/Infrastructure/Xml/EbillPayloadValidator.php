<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Infrastructure\Xml;

use DOMDocument;
use Modules\SwissEbill\Domain\Exceptions\EbillValidationException;

/**
 * Validates a SIX eBill XML payload against the module XSD before it leaves the system.
 */
final class EbillPayloadValidator
{
    public const NAMESPACE_URI = 'urn:swiss-ebill:invoice:1';

    /**
     * @throws EbillValidationException when the XML is malformed or breaks the schema
     */
    public function assertValid(string $xml): void
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new DOMDocument;

            if (! $document->loadXML($xml, LIBXML_NONET)) {
                throw new EbillValidationException([['code' => 'xml_malformed', 'field' => 'payload']]);
            }

            if (! $document->schemaValidate(self::schemaPath())) {
                $violations = [];

                foreach (libxml_get_errors() as $error) {
                    $violations[] = ['code' => 'xsd_invalid', 'field' => 'payload:'.trim($error->message).' (line '.$error->line.')'];
                }

                throw new EbillValidationException($violations !== [] ? $violations : [['code' => 'xsd_invalid', 'field' => 'payload']]);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public static function schemaPath(): string
    {
        return dirname(__DIR__, 3).'/resources/xsd/swiss-ebill-invoice.xsd';
    }
}
