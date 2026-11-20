<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Infrastructure\Providers;

use Modules\OcrExtraction\Domain\ValueObjects\ExtractedPayloadDto;

/**
 * Rule-based reading of plain OCR text (no model): identifiers, header fields, totals, Swiss VAT rate rows and simple
 * `description quantity price total` lines. Used by the Tesseract adapter, so the module works with no AI service at all.
 * Confidences are deliberately modest: patterns are not understanding.
 */
final class HeuristicInvoiceTextParser
{
    private const float PatternConfidence = 72.0;

    private const float LabelledConfidence = 80.0;

    public function parse(string $text): ExtractedPayloadDto
    {
        $confidence = [];
        $note = static function (string $key, float $value) use (&$confidence): void {
            $confidence[$key] = $value;
        };

        $iban = $this->first('/\b([A-Z]{2}\d{2}(?:\s?[A-Z0-9]{4}){3,7}(?:\s?[A-Z0-9]{1,4})?)\b/', $text);
        $uid = $this->first('/\b(CHE[-\s.]?\d{3}[.\s]?\d{3}[.\s]?\d{3})\b/i', $text);
        $vat = $this->first('/\b(?:P\.?\s?IVA|Partita IVA|USt-?IdNr\.?|MWST-?Nr\.?|TVA)[:\s.]*((?:IT)?\d{11})\b/i', $text)
            ?? $this->first('/\b(IT\d{11})\b/', $text);
        $number = $this->first('/(?:fattura|rechnung|facture|invoice|ricevuta|beleg)\s*(?:n[r°o.]*\s*|nummer\s*|number\s*|#\s*)?[:#]?\s*([A-Z0-9][A-Z0-9\/._-]{2,})/i', $text);
        $issue = $this->first('/(?:data(?:\s+fattura)?|datum|date|rechnungsdatum)[:\s]+(\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4})/i', $text);
        $due = $this->first('/(?:scadenza|fällig(?:keit)?(?:sdatum)?|échéance|due(?:\s+date)?|zahlbar bis)[:\s]+(\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4})/i', $text);
        $reference = $this->first('/\b(\d{2}\s?\d{5}\s?\d{5}\s?\d{5}\s?\d{5}\s?\d{5})\b/', $text);
        $currency = $this->first('/\b(CHF|EUR)\b/', $text);
        $gross = $this->first('/(?:totale(?:\s+fattura|\s+documento)?|total(?:e)?\s*(?:ttc|inkl\.?\s*mwst|brutto)?|gesamtbetrag|rechnungsbetrag|importo totale)[^\d\n]{0,20}(?:CHF|EUR|€)?\s*([\d\'’.,]+\d)/i', $text);
        $net = $this->first('/(?:imponibile|netto|nettobetrag|total\s+ht|sous-total|subtotal|totale netto)[^\d\n]{0,20}(?:CHF|EUR|€)?\s*([\d\'’.,]+\d)/i', $text);
        $tax = $this->first('/(?:totale iva|iva|mwst\.?|mehrwertsteuer|tva|vat)[^\d\n%]{0,20}(?:CHF|EUR|€)?\s*([\d\'’.,]+\d)\s*$/im', $text);

        $order = $this->first('/(?:ordine(?:\s+d[\'’]acquisto)?|bestellung(?:snummer)?|commande|purchase order|P\.?O\.?|ordine n[°.]?)\s*(?:n[r°o.]*|#|:)?\s*([A-Z]{0,4}[-\/]?\d[\w\/-]{2,})/i', $text);

        $vendorName = null;

        foreach (preg_split('/\R/', trim($text)) ?: [] as $line) {
            if (preg_match('/[A-Za-zÀ-ÿ]{3}/u', $line)) {
                $vendorName = trim($line);

                break;
            }
        }

        $data = [
            'vendor' => ['name' => $vendorName, 'uid' => $uid, 'vat_number' => $vat, 'iban' => $iban],
            'document' => ['number' => $number, 'issue_date' => $issue, 'due_date' => $due, 'currency' => $currency, 'qr_reference' => $reference],
            'order_references' => $order !== null ? [$order] : [],
            'totals' => ['net' => $net, 'vat' => $tax, 'gross' => $gross],
            'vat_breakdown' => $this->vatBreakdown($text),
            'lines' => $this->lines($text),
        ];

        $payload = ExtractedPayloadDto::fromArray($data);

        foreach ($payload->filledFieldKeys() as $key) {
            $note($key, in_array($key, ['vendor.iban', 'vendor.uid', 'vendor.vat_number', 'header.qr_reference', 'header.currency'], true) ? self::LabelledConfidence : self::PatternConfidence);
        }

        foreach (array_keys($payload->lines) as $index) {
            foreach (['description', 'quantity', 'unit_price', 'net_amount'] as $field) {
                $note("lines[{$index}].{$field}", self::PatternConfidence - 5);
            }
        }

        return new ExtractedPayloadDto($payload->vendor, $payload->document, $payload->orderReferences, $payload->totals, $payload->vatBreakdown, $payload->lines, $confidence);
    }

    /**
     * Rows such as "8.1 %  1'000.00  81.00" (rate, net, VAT) for the Swiss rates 8.1, 2.6 and 3.8 and any other rate.
     *
     * @return list<array{rate: string, net: string, vat: string}>
     */
    private function vatBreakdown(string $text): array
    {
        $rows = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (! preg_match('/\b(\d{1,2}(?:[.,]\d{1,2})?)\s*%/', $line, $rate)) {
                continue;
            }

            preg_match_all('/\d[\d\'’]*[.,]\d{2}\b/', preg_replace('/\b\d{1,2}(?:[.,]\d{1,2})?\s*%/', '', $line) ?? $line, $amounts);
            $values = array_map(static fn (string $amount): ?float => ExtractedPayloadDto::toFloat($amount), $amounts[0]);

            if (count($values) < 2 || $values[0] === null || $values[1] === null) {
                continue;
            }

            $rows[] = ['rate' => $rate[1], 'net' => (string) $values[0], 'vat' => (string) $values[1]];
        }

        return $rows;
    }

    /**
     * Lines ending in quantity, unit price and amount.
     *
     * @return list<array{description: string, quantity: string, unit_price: string, net_amount: string}>
     */
    private function lines(string $text): array
    {
        $lines = [];

        foreach (preg_split('/\R/', $text) ?: [] as $row) {
            if (preg_match('/^\s*(?:\d{1,3}[.)]?\s+)?([A-Za-zÀ-ÿ][^\d\n]{2,}?[\wÀ-ÿ)])\s+(\d+(?:[.,]\d+)?)\s+(\d[\d\'’.,]*)\s+(\d[\d\'’.,]*)\s*$/u', $row, $match)) {
                $lines[] = ['description' => trim($match[1]), 'quantity' => $match[2], 'unit_price' => $match[3], 'net_amount' => $match[4]];
            }
        }

        return $lines;
    }

    private function first(string $pattern, string $text): ?string
    {
        return preg_match($pattern, $text, $match) ? trim($match[1]) : null;
    }
}
