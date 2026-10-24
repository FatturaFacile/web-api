<?php

declare(strict_types=1);

namespace App\Services\ItalianSdi;

use InvalidArgumentException;
use SimpleXMLElement;

/**
 * Reads incoming FatturaPA (v1.2.x) documents into plain arrays: supplier, recipient and one entry per
 * `FatturaElettronicaBody` (a file may carry several documents).
 */
class FatturaPaParser
{
    /**
     * @return array{supplier: array{country: string, vat: ?string, fiscal_code: ?string, name: string}, recipient_vat: ?string, recipient_fiscal_code: ?string, documents: array<int, array{document_type: string, currency: string, date: string, number: string, total: float, net: float, tax: float, due_date: ?string}>}
     */
    public function parse(string $xml): array
    {
        $document = $this->load($xml);

        $supplier = $document->xpath('//FatturaElettronicaHeader/CedentePrestatore/DatiAnagrafici')[0] ?? null;
        $recipient = $document->xpath('//FatturaElettronicaHeader/CessionarioCommittente/DatiAnagrafici')[0] ?? null;
        $bodies = $document->xpath('//FatturaElettronicaBody');

        if ($supplier === null || $bodies === [] || $bodies === false) {
            throw new InvalidArgumentException(__('purchases.invalid_fatturapa'));
        }

        $name = $this->value($supplier, 'Anagrafica/Denominazione')
            ?? trim(($this->value($supplier, 'Anagrafica/Nome') ?? '').' '.($this->value($supplier, 'Anagrafica/Cognome') ?? ''));

        $documents = [];

        foreach ($bodies as $body) {
            $net = 0.0;
            $tax = 0.0;

            foreach ($body->xpath('DatiBeniServizi/DatiRiepilogo') ?: [] as $summary) {
                $net += (float) $this->value($summary, 'ImponibileImporto');
                $tax += (float) $this->value($summary, 'Imposta');
            }

            $number = $this->value($body, 'DatiGenerali/DatiGeneraliDocumento/Numero');
            $date = $this->value($body, 'DatiGenerali/DatiGeneraliDocumento/Data');

            if ($number === null || $date === null) {
                throw new InvalidArgumentException(__('purchases.invalid_fatturapa'));
            }

            $documents[] = [
                'document_type' => $this->value($body, 'DatiGenerali/DatiGeneraliDocumento/TipoDocumento') ?? 'TD01',
                'currency' => $this->value($body, 'DatiGenerali/DatiGeneraliDocumento/Divisa') ?? 'EUR',
                'date' => $date,
                'number' => $number,
                'total' => round((float) ($this->value($body, 'DatiGenerali/DatiGeneraliDocumento/ImportoTotaleDocumento') ?? $net + $tax), 2),
                'net' => round($net, 2),
                'tax' => round($tax, 2),
                'due_date' => $this->value($body, 'DatiPagamento/DettaglioPagamento/DataScadenzaPagamento'),
            ];
        }

        return [
            'supplier' => [
                'country' => $this->value($supplier, 'IdFiscaleIVA/IdPaese') ?? 'IT',
                'vat' => $this->value($supplier, 'IdFiscaleIVA/IdCodice'),
                'fiscal_code' => $this->value($supplier, 'CodiceFiscale'),
                'name' => $name,
            ],
            'recipient_vat' => $recipient !== null ? $this->value($recipient, 'IdFiscaleIVA/IdCodice') : null,
            'recipient_fiscal_code' => $recipient !== null ? $this->value($recipient, 'CodiceFiscale') : null,
            'documents' => $documents,
        ];
    }

    /**
     * Extract only the recipient VAT, used to route a webhook payload to the right tenant.
     */
    public function recipientVat(string $xml): ?string
    {
        $recipient = $this->load($xml)->xpath('//FatturaElettronicaHeader/CessionarioCommittente/DatiAnagrafici')[0] ?? null;

        return $recipient !== null ? $this->value($recipient, 'IdFiscaleIVA/IdCodice') : null;
    }

    private function load(string $xml): SimpleXMLElement
    {
        // Namespaces, element prefixes and prefixed attributes are stripped so plain XPath works for every provider flavour.
        $clean = (string) preg_replace('/\sxmlns(:\w+)?="[^"]*"/', '', $xml);
        $clean = (string) preg_replace('/(<\/?)\w+:/', '$1', $clean);
        $clean = (string) preg_replace('/\s\w+:\w+="[^"]*"/', '', $clean);

        $previous = libxml_use_internal_errors(true);

        try {
            $document = simplexml_load_string($clean);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($document === false || $document->getName() !== 'FatturaElettronica') {
            throw new InvalidArgumentException(__('purchases.invalid_fatturapa'));
        }

        return $document;
    }

    private function value(SimpleXMLElement $node, string $path): ?string
    {
        $found = $node->xpath($path)[0] ?? null;
        $value = $found !== null ? trim((string) $found) : '';

        return $value === '' ? null : $value;
    }
}
