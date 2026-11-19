<?php

declare(strict_types=1);

namespace App\Services\Banking\Iso20022;

use App\Exceptions\Pain001Exception;
use DOMDocument;

/**
 * Validates a pain.001 document against the bundled ISO 20022 schema of its version
 * (resources/schemas/iso20022/pain.001.001.NN.xsd).
 */
class Pain001SchemaValidator
{
    /**
     * @throws Pain001Exception listing the schema violations
     */
    public function validate(string $xml, Pain001Version $version): void
    {
        $errors = $this->errors($xml, $version);

        if ($errors !== []) {
            throw new Pain001Exception('The payment file does not validate against pain.001.001.'.$version->value.'.', $errors);
        }
    }

    /**
     * @return array<int, string> schema violations, empty when the document is valid
     */
    public function errors(string $xml, Pain001Version $version): array
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new DOMDocument;

            if (! $document->loadXML($xml, LIBXML_NONET)) {
                return ['The document is not well-formed XML.'];
            }

            if ($document->schemaValidate($version->schemaPath())) {
                return [];
            }

            return array_values(array_unique(array_map(
                fn ($error): string => trim($error->message).' (line '.$error->line.')',
                libxml_get_errors()
            )));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
