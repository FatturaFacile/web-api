<?php

declare(strict_types=1);

namespace Modules\Esterometro\Services;

use DOMDocument;
use DOMXPath;
use Modules\Esterometro\Domain\Enums\SelfInvoiceDocumentType;
use Modules\SDI\Services\FatturaPaSchemaValidator;
use Modules\SDI\Services\SogeiCongruityValidator;

/**
 * Validates TD16–TD28 FatturaPA XML against XSD schema, SOGEI congruity rules, and role-inversion semantics.
 */
final class SelfInvoiceXmlValidator
{
    public function __construct(
        private readonly FatturaPaSchemaValidator $schemaValidator,
        private readonly SogeiCongruityValidator $congruityValidator,
    ) {}

    /**
     * @return array{valid: bool, errors: list<string>}
     */
    public function validate(string $xml, ?string $tenantId = null): array
    {
        $errors = [];

        $dom = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (! $loaded) {
            return ['valid' => false, 'errors' => ['Malformed XML payload.']];
        }

        $xpath = new DOMXPath($dom);
        $tipoDocumento = trim((string) $xpath->evaluate('string(//*[local-name()="TipoDocumento"])'));
        if (! in_array($tipoDocumento, SelfInvoiceDocumentType::values(), true)) {
            $errors[] = "TipoDocumento '{$tipoDocumento}' is not a supported self-invoice type (TD16-TD28).";
        }

        $soggettoEmittente = trim((string) $xpath->evaluate('string(//*[local-name()="SoggettoEmittente"])'));
        if ($soggettoEmittente !== 'CC') {
            $errors[] = "SoggettoEmittente must be 'CC' for buyer-issued integration/self-invoices.";
        }

        $divisa = trim((string) $xpath->evaluate('string(//*[local-name()="Divisa"])'));
        if ($divisa !== 'EUR') {
            $errors[] = "Divisa must be 'EUR' on Italian Esterometro/Reverse Charge XML.";
        }

        $schemaErrors = $this->schemaValidator->errors($xml);
        foreach ($schemaErrors as $err) {
            $errors[] = $err;
        }

        $congruity = $this->congruityValidator->validate($xml, $tenantId);
        if (! $congruity->isValid()) {
            foreach ($congruity->errors() as $err) {
                $errors[] = $err->message;
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => array_values(array_unique($errors)),
        ];
    }
}
