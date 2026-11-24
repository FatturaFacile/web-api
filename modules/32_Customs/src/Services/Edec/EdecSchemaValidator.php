<?php

declare(strict_types=1);

namespace Modules\Customs\Services\Edec;

use DOMDocument;
use LibXMLError;

/**
 * Validates messages against the XML schemas published by BAZG, kept unchanged in resources/xsd.
 */
final class EdecSchemaValidator
{
    public const string DECLARATION = 'edec_v_4_0.xsd';

    public const string DECLARATION_RESPONSE = 'edecResponse_v_4_0.xsd';

    public const string RECEIPT_REQUEST = 'edecReceiptRequest_v_3_0.xsd';

    public const string RECEIPT_RESPONSE = 'edecReceiptResponse_v_3_0.xsd';

    /**
     * @return list<string> the schema violations, empty when the message is valid
     */
    public function errors(string $xml, string $schema): array
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new DOMDocument;

            if ($document->loadXML($xml, LIBXML_NONET)) {
                $document->schemaValidate(dirname(__DIR__, 3).'/resources/xsd/'.$schema);
            }

            return array_map(
                static fn (LibXMLError $error): string => trim($error->message),
                libxml_get_errors(),
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
