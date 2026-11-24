<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use App\Models\PassiveInvoice;

/**
 * Reads the purchase order references an incoming supplier invoice carries: the FatturaPA `DatiOrdineAcquisto` block
 * (Mod 026), the order reference elements of a Swiss eBill XML (Mod 025) and the free text of an OCR-read QR-bill
 * (Mod 023 / Mod 092).
 */
final class PurchaseOrderReferenceExtractor
{
    /** Elements of an eBill / UBL-like XML that carry the order number of the buyer. */
    private const array EbillOrderTags = ['PurchaseOrderReference', 'BuyerOrderNumber', 'CustomerOrderNumber', 'OrderReference', 'OrderNumber', 'BuyerReference'];

    /**
     * Structured references, in the order they are found.
     *
     * @return list<array{value: string, source: string}>
     */
    public function extract(PassiveInvoice $invoice): array
    {
        $found = [];
        $xml = (string) ($invoice->xml_payload ?? '');

        if ($xml !== '') {
            foreach ($this->fromXml($xml) as $reference) {
                $found[] = $reference;
            }
        }

        $unique = [];

        foreach ($found as $reference) {
            $unique[strtoupper($reference['value'])] ??= $reference;
        }

        return array_values($unique);
    }

    /**
     * The searchable text of the invoice (XML and OCR payload), upper-case and without separators, to look for the
     * number of a known order in it.
     *
     * @return list<array{text: string, source: string}>
     */
    public function haystacks(PassiveInvoice $invoice): array
    {
        $texts = [];
        $xml = (string) ($invoice->xml_payload ?? '');
        $ocr = (string) ($invoice->qr_raw_payload ?? '');

        if ($xml !== '') {
            $texts[] = ['text' => self::normalize(strip_tags($xml)), 'source' => $this->isFatturaPa($xml) ? 'xml' : 'ebill'];
        }

        if ($ocr !== '') {
            $texts[] = ['text' => self::normalize($ocr), 'source' => 'ocr'];
        }

        return $texts;
    }

    public static function normalize(string $value): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($value));
    }

    /**
     * @return list<array{value: string, source: string}>
     */
    private function fromXml(string $xml): array
    {
        $found = [];
        $isFatturaPa = $this->isFatturaPa($xml);

        if ($isFatturaPa) {
            if (preg_match_all('#<(?:\w+:)?DatiOrdineAcquisto>(.*?)</(?:\w+:)?DatiOrdineAcquisto>#s', $xml, $blocks) > 0) {
                foreach ($blocks[1] as $block) {
                    if (preg_match('#<(?:\w+:)?IdDocumento>\s*([^<]+?)\s*</#', $block, $id) === 1) {
                        $found[] = ['value' => trim(html_entity_decode($id[1])), 'source' => 'xml'];
                    }
                }
            }

            return $found;
        }

        foreach (self::EbillOrderTags as $tag) {
            if (preg_match_all('#<(?:\w+:)?'.$tag.'>\s*([^<]+?)\s*</#', $xml, $matches) > 0) {
                foreach ($matches[1] as $value) {
                    $found[] = ['value' => trim(html_entity_decode($value)), 'source' => 'ebill'];
                }
            }
        }

        return $found;
    }

    private function isFatturaPa(string $xml): bool
    {
        return stripos($xml, 'FatturaElettronica') !== false;
    }
}
