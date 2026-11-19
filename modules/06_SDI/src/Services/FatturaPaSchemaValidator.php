<?php

declare(strict_types=1);

namespace Modules\SDI\Services;

use DOMDocument;

/**
 * Validates a file against the official FatturaPA schema published by Agenzia delle Entrate that matches its format:
 * FPR12 (private parties), FPA12 (public administration) and FSM10 (simplified invoice).
 */
final class FatturaPaSchemaValidator
{
    private const array Schemas = [
        'FPR12' => 'Schema_VFPR12_v1.2.3.xsd',
        'FPA12' => 'Schema_VFPA12_V1.2.3.xsd',
        'FSM10' => 'Schema_VFSM10v_1.0.2.xsd',
    ];

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
                $document->schemaValidate(dirname(__DIR__, 2).'/resources/xsd/'.$this->schemaFor($document));
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

    public function schemaFor(DOMDocument $document): string
    {
        $format = $document->documentElement?->getAttribute('versione') ?? '';

        return self::Schemas[$format] ?? 'Schema_del_file_xml_FatturaPA_v1.2.3.xsd';
    }
}
