<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Infrastructure\Extraction;

use App\Services\ItalianSdi\FatturaPaParser;
use InvalidArgumentException;
use Modules\OcrExtraction\Domain\ValueObjects\ExtractedPayloadDto;

/**
 * Maps a native FatturaPA XML (schema 1.2.x) onto the extraction payload with no OCR: every field is exact. The header,
 * dates and lines come from the SDI parser of the invoicing module; the IBAN, the VAT summary and the purchase order
 * references are read from the same XML here.
 */
final readonly class FatturaPaExtractor
{
    public function __construct(private FatturaPaParser $parser) {}

    public function looksLikeFatturaPa(string $contents): bool
    {
        return stripos($contents, 'FatturaElettronica') !== false;
    }

    /**
     * @return array{payload: ExtractedPayloadDto, document_type: string}|null null when the XML is not a readable FatturaPA
     */
    public function extract(string $xml): ?array
    {
        try {
            $parsed = $this->parser->parse($xml);
        } catch (InvalidArgumentException) {
            return null;
        }

        $document = $parsed['documents'][0];
        $root = XmlDocument::load($xml);
        $body = $root?->xpath('//FatturaElettronicaBody')[0] ?? null;

        $supplier = $parsed['supplier'];
        $vat = $supplier['vat'] !== null ? $supplier['country'].$supplier['vat'] : null;

        $breakdown = [];
        $references = [];
        $iban = null;

        if ($body !== null) {
            foreach ($body->xpath('DatiBeniServizi/DatiRiepilogo') ?: [] as $summary) {
                $breakdown[] = [
                    'rate' => XmlDocument::text($summary, 'AliquotaIVA') ?? '0',
                    'net' => XmlDocument::text($summary, 'ImponibileImporto') ?? '0',
                    'vat' => XmlDocument::text($summary, 'Imposta') ?? '0',
                ];
            }

            foreach ($body->xpath('DatiGenerali/DatiOrdineAcquisto/IdDocumento') ?: [] as $reference) {
                $references[] = trim((string) $reference);
            }

            $iban = XmlDocument::text($body, 'DatiPagamento/DettaglioPagamento/IBAN');
        }

        $lines = array_map(static fn (array $line): array => [
            'description' => $line['description'],
            'quantity' => $line['quantity'] ?? '1',
            'unit_price' => $line['unit_price'],
            'net_amount' => $line['line_total'],
            'vat_rate' => $line['tax_rate'],
        ], $document['lines']);

        $payload = ExtractedPayloadDto::fromArray([
            'vendor' => ['name' => $supplier['name'], 'vat_number' => $vat, 'fiscal_code' => $supplier['fiscal_code'], 'iban' => $iban],
            'document' => ['number' => $document['number'], 'issue_date' => $document['date'], 'due_date' => $document['due_date'], 'currency' => $document['currency']],
            'order_references' => $references,
            'totals' => ['net' => $document['net'], 'vat' => $document['tax'], 'gross' => $document['total']],
            'vat_breakdown' => $breakdown,
            'lines' => $lines,
        ]);

        return ['payload' => $this->withFullConfidence($payload), 'document_type' => $document['document_type']];
    }

    private function withFullConfidence(ExtractedPayloadDto $payload): ExtractedPayloadDto
    {
        $confidence = [];

        foreach ($payload->filledFieldKeys() as $key) {
            $confidence[$key] = 100.0;
        }

        foreach (array_keys($payload->lines) as $index) {
            foreach (['description', 'quantity', 'unit_price', 'net_amount', 'vat_rate'] as $field) {
                $confidence["lines[{$index}].{$field}"] = 100.0;
            }
        }

        return new ExtractedPayloadDto($payload->vendor, $payload->document, $payload->orderReferences, $payload->totals, $payload->vatBreakdown, $payload->lines, $confidence);
    }
}
