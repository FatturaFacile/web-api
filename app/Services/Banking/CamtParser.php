<?php

declare(strict_types=1);

namespace App\Services\Banking;

use InvalidArgumentException;
use SimpleXMLElement;

class CamtParser
{
    /**
     * Parse an ISO 20022 camt.053/camt.054 document into credit entries (incoming payments).
     * Debits and reversals are ignored. One entry is produced per transaction detail (`TxDtls`).
     *
     * @return array<int, array{hash: string, booking_date: ?string, amount: float, currency: string, reference: ?string, remittance_info: ?string, debtor_name: ?string, fee_amount: float, reference_type: ?string}>
     */
    public function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = simplexml_load_string((string) preg_replace('/\sxmlns(:\w+)?="[^"]*"/', '', $xml));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($document === false || $document->xpath('//Ntry') === []) {
            throw new InvalidArgumentException(__('bank.invalid_camt'));
        }

        $entries = [];

        foreach ($document->xpath('//Ntry') as $ntry) {
            if ((string) $ntry->CdtDbtInd !== 'CRDT' || strtolower((string) $ntry->RvslInd) === 'true') {
                continue;
            }

            $date = (string) ($ntry->BookgDt->Dt ?? '') ?: substr((string) ($ntry->BookgDt->DtTm ?? ''), 0, 10)
                ?: (string) ($ntry->ValDt->Dt ?? '');
            $details = $ntry->xpath('NtryDtls/TxDtls') ?: [null];

            foreach ($details as $index => $tx) {
                $entries[] = $this->entry($ntry, $tx, $date ?: null, count($details) > 1, $index);
            }
        }

        return $entries;
    }

    /**
     * @return array{hash: string, booking_date: ?string, amount: float, currency: string, reference: ?string, remittance_info: ?string, debtor_name: ?string, fee_amount: float, reference_type: ?string}
     */
    private function entry(SimpleXMLElement $ntry, ?SimpleXMLElement $tx, ?string $date, bool $split, int $index): array
    {
        $amountNode = $tx !== null && $split
            ? ($tx->xpath('AmtDtls/TxAmt/Amt')[0] ?? $tx->xpath('Amt')[0] ?? $ntry->Amt)
            : $ntry->Amt;

        $first = fn (?SimpleXMLElement $node, string $path): ?string => ($node !== null && isset($node->xpath($path)[0]))
            ? (trim((string) $node->xpath($path)[0]) ?: null)
            : null;

        $reference = $first($tx, 'RmtInf/Strd/CdtrRefInf/Ref');
        $ustrd = $tx !== null ? implode(' ', array_map('trim', array_map('strval', $tx->xpath('RmtInf/Ustrd') ?: []))) : '';
        $accountServicerRef = $first($tx, 'Refs/AcctSvcrRef') ?? trim((string) $ntry->AcctSvcrRef);
        $endToEnd = $first($tx, 'Refs/EndToEndId');
        $amount = round((float) $amountNode, 2);
        $currency = (string) ($amountNode['Ccy'] ?? $ntry->Amt['Ccy'] ?? 'CHF');

        return [
            'hash' => hash('sha256', implode('|', [$accountServicerRef, $endToEnd, $reference, $amount, $currency, $date, $ustrd, $index])),
            'booking_date' => $date,
            'amount' => $amount,
            'currency' => $currency,
            'reference' => $reference !== null ? preg_replace('/\s+/', '', $reference) : null,
            'remittance_info' => $ustrd !== '' ? mb_substr($ustrd, 0, 500) : null,
            'debtor_name' => $first($tx, 'RltdPties/Dbtr/Nm') ?? $first($tx, 'RltdPties/Dbtr/Pty/Nm'),
            'fee_amount' => $this->fee($ntry, $tx, $split),
            'reference_type' => $this->referenceType($tx, $reference),
        ];
    }

    /**
     * Bank charges of the entry: transaction-level charges when present, otherwise the entry-level total
     * (entry-level charges of a multi-transaction entry cannot be split, so they are ignored there).
     */
    private function fee(SimpleXMLElement $ntry, ?SimpleXMLElement $tx, bool $split): float
    {
        $sum = function (?SimpleXMLElement $node): float {
            if ($node === null) {
                return 0.0;
            }

            $total = $node->xpath('Chrgs/TtlChrgsAndTaxAmt')[0] ?? null;

            if ($total !== null) {
                return round((float) $total, 2);
            }

            return round(array_sum(array_map('floatval', $node->xpath('Chrgs/Rcrd/Amt') ?: [])), 2);
        };

        $fromTransaction = $sum($tx);

        return $fromTransaction > 0 || $split ? $fromTransaction : $sum($ntry);
    }

    /**
     * Payment channel from the structured creditor reference: QRR (QR reference), ISR (legacy PVR/ESR slip),
     * SCOR/RF (creditor reference); anything else with a reference is "other".
     */
    private function referenceType(?SimpleXMLElement $tx, ?string $reference): ?string
    {
        if ($reference === null) {
            return null;
        }

        $proprietary = strtoupper(trim((string) ($tx?->xpath('RmtInf/Strd/CdtrRefInf/Tp/CdOrPrtry/Prtry')[0] ?? '')));
        $code = strtoupper(trim((string) ($tx?->xpath('RmtInf/Strd/CdtrRefInf/Tp/CdOrPrtry/Cd')[0] ?? '')));

        return match (true) {
            $proprietary === 'QRR' => 'qr',
            str_contains($proprietary, 'ISR') => 'pvr',
            $code === 'SCOR' || str_starts_with(strtoupper($reference), 'RF') => 'scor',
            default => 'other',
        };
    }
}
