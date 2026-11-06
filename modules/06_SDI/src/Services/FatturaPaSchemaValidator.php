<?php

declare(strict_types=1);

namespace Modules\SDI\Services;

use DOMDocument;

/**
 * Validates a file against the official FatturaPA schema v1.2.3 (FPR12 and FPA12), published by Agenzia delle Entrate.
 */
final class FatturaPaSchemaValidator
{
    /**
     * @return list<string> the schema violations, empty when the file is valid
     */
    public function errors(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new DOMDocument;

            if ($document->loadXML($xml, LIBXML_NONET)) {
                $document->schemaValidate(dirname(__DIR__, 2).'/resources/xsd/Schema_del_file_xml_FatturaPA_v1.2.3.xsd');
            }

            return array_map(
                fn (\LibXMLError $error): string => trim($error->message),
                libxml_get_errors(),
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
