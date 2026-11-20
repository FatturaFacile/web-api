<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Infrastructure\Extraction;

use Modules\OcrExtraction\Domain\ValueObjects\ExtractedPayloadDto;
use SimpleXMLElement;

/**
 * Reads the invoice XML attached to a PDF/A-3 (ZUGFeRD 2.x, Factur-X: a UN/CEFACT CrossIndustryInvoice) and maps it onto
 * the extraction payload with no OCR. Attachments are found by scanning the PDF for embedded-file streams and inflating
 * them, so no PDF library is needed.
 */
final class ZugferdExtractor
{
    /**
     * The CII XML attached to the PDF, or null when the PDF carries none.
     */
    public function embeddedXml(string $pdf): ?string
    {
        if (! str_starts_with($pdf, '%PDF')) {
            return null;
        }

        if (preg_match_all('/<<(?:(?!>>\s*stream).)*?\/Type\s*\/EmbeddedFile(?:(?!>>\s*stream).)*?>>\s*stream\r?\n/s', $pdf, $headers, PREG_OFFSET_CAPTURE) === 0) {
            return null;
        }

        foreach ($headers[0] as [$header, $offset]) {
            $start = $offset + strlen($header);
            $end = strpos($pdf, 'endstream', $start);

            if ($end === false) {
                continue;
            }

            $data = rtrim(substr($pdf, $start, $end - $start), "\r\n");

            if (stripos($header, 'FlateDecode') !== false) {
                $inflated = @gzuncompress($data);
                $data = $inflated === false ? (@gzinflate(substr($data, 2)) ?: '') : $inflated;
            }

            if (str_contains($data, 'CrossIndustryInvoice')) {
                return ltrim($data, "\xEF\xBB\xBF");
            }
        }

        return null;
    }

    public function extract(string $xml): ?ExtractedPayloadDto
    {
        $root = XmlDocument::load($xml);

        if (! $root instanceof SimpleXMLElement || $root->getName() !== 'CrossIndustryInvoice') {
            return null;
        }

        $seller = $root->xpath('//SupplyChainTradeTransaction/ApplicableHeaderTradeAgreement/SellerTradeParty')[0] ?? null;
        $settlement = $root->xpath('//SupplyChainTradeTransaction/ApplicableHeaderTradeSettlement')[0] ?? null;

        if ($seller === null || $settlement === null) {
            return null;
        }

        $summary = $settlement->xpath('SpecifiedTradeSettlementHeaderMonetarySummation')[0] ?? null;
        $vat = XmlDocument::text($seller, "SpecifiedTaxRegistration/ID[@schemeID='VA']");
        $uid = XmlDocument::text($seller, 'SpecifiedLegalOrganization/ID');

        $breakdown = [];

        foreach ($settlement->xpath('ApplicableTradeTax') ?: [] as $tax) {
            $breakdown[] = [
                'rate' => XmlDocument::text($tax, 'RateApplicablePercent') ?? '0',
                'net' => XmlDocument::text($tax, 'BasisAmount') ?? '0',
                'vat' => XmlDocument::text($tax, 'CalculatedAmount') ?? '0',
            ];
        }

        $lines = [];

        foreach ($root->xpath('//SupplyChainTradeTransaction/IncludedSupplyChainTradeLineItem') ?: [] as $item) {
            $lines[] = [
                'description' => XmlDocument::text($item, 'SpecifiedTradeProduct/Name') ?? '',
                'quantity' => XmlDocument::text($item, 'SpecifiedLineTradeDelivery/BilledQuantity') ?? '1',
                'unit_price' => XmlDocument::text($item, 'SpecifiedLineTradeAgreement/NetPriceProductTradePrice/ChargeAmount'),
                'net_amount' => XmlDocument::text($item, 'SpecifiedLineTradeSettlement/SpecifiedTradeSettlementLineMonetarySummation/LineTotalAmount'),
                'vat_rate' => XmlDocument::text($item, 'SpecifiedLineTradeSettlement/ApplicableTradeTax/RateApplicablePercent'),
            ];
        }

        $order = XmlDocument::text($root, '//SupplyChainTradeTransaction/ApplicableHeaderTradeAgreement/BuyerOrderReferencedDocument/IssuerAssignedID');

        $payload = ExtractedPayloadDto::fromArray([
            'vendor' => [
                'name' => XmlDocument::text($seller, 'Name'),
                'vat_number' => $vat,
                'uid' => $uid !== null && stripos($uid, 'CHE') === 0 ? $uid : null,
                'iban' => XmlDocument::text($settlement, 'SpecifiedTradeSettlementPaymentMeans/PayeePartyCreditorFinancialAccount/IBANID'),
            ],
            'document' => [
                'number' => XmlDocument::text($root, '//ExchangedDocument/ID'),
                'issue_date' => XmlDocument::text($root, '//ExchangedDocument/IssueDateTime/DateTimeString'),
                'due_date' => XmlDocument::text($settlement, 'SpecifiedTradePaymentTerms/DueDateDateTime/DateTimeString'),
                'currency' => XmlDocument::text($settlement, 'InvoiceCurrencyCode'),
            ],
            'order_references' => $order !== null ? [$order] : [],
            'totals' => [
                'net' => $summary !== null ? XmlDocument::text($summary, 'TaxBasisTotalAmount') ?? XmlDocument::text($summary, 'LineTotalAmount') : null,
                'vat' => $summary !== null ? XmlDocument::text($summary, 'TaxTotalAmount') : null,
                'gross' => $summary !== null ? XmlDocument::text($summary, 'GrandTotalAmount') : null,
            ],
            'vat_breakdown' => $breakdown,
            'lines' => $lines,
        ]);

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
