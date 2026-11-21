<?php

declare(strict_types=1);

namespace Modules\SwissFiscal\Infrastructure\Iso20022;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Modules\SwissFiscal\Domain\Exceptions\SwissFiscalException;
use Modules\SwissFiscal\Domain\Services\PaymentReference;

/**
 * Reads an ISO 20022 camt.054 (bank-to-customer debit/credit notification) into reconciliation entries. The parser is
 * namespace-agnostic, so every camt.054.001.0x version used by Swiss banks is accepted.
 */
final class Camt054Parser
{
    /**
     * @return list<array{reference: string|null, reference_valid: bool, amount: string, currency: string, credit: bool, booking_date: string|null, value_date: string|null, debtor: string|null}>
     */
    public function parse(string $xml): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded || $document->documentElement?->localName !== 'Document') {
            throw new SwissFiscalException('The file is not a camt.054 document.');
        }

        $xpath = new DOMXPath($document);
        $entries = [];

        foreach ($xpath->query('//*[local-name()="Ntry"]') ?: [] as $entry) {
            if (! $entry instanceof DOMElement) {
                continue;
            }

            $amountNode = $this->node($xpath, '*[local-name()="Amt"]', $entry);
            if (! $amountNode instanceof DOMElement) {
                continue;
            }

            $reference = $this->text($xpath, './/*[local-name()="CdtrRefInf"]/*[local-name()="Ref"]', $entry);

            $entries[] = [
                'reference' => $reference,
                'reference_valid' => $reference !== null
                    && (PaymentReference::isValidQrReference($reference) || PaymentReference::isValidCreditorReference($reference)),
                'amount' => trim($amountNode->textContent),
                'currency' => $amountNode->getAttribute('Ccy'),
                'credit' => $this->text($xpath, '*[local-name()="CdtDbtInd"]', $entry) === 'CRDT',
                'booking_date' => $this->text($xpath, '*[local-name()="BookgDt"]/*[local-name()="Dt"]', $entry),
                'value_date' => $this->text($xpath, '*[local-name()="ValDt"]/*[local-name()="Dt"]', $entry),
                'debtor' => $this->text($xpath, './/*[local-name()="RltdPties"]/*[local-name()="Dbtr"]//*[local-name()="Nm"]', $entry),
            ];
        }

        return $entries;
    }

    private function text(DOMXPath $xpath, string $query, DOMElement $context): ?string
    {
        $node = $this->node($xpath, $query, $context);
        $value = $node === null ? '' : trim($node->textContent);

        return $value === '' ? null : $value;
    }

    private function node(DOMXPath $xpath, string $query, DOMElement $context): ?DOMNode
    {
        $list = $xpath->query($query, $context);

        return $list === false ? null : $list->item(0);
    }
}
