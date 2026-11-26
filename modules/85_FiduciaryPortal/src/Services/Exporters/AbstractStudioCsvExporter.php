<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Services\Exporters;

use Modules\FiduciaryPortal\Models\FiduciaryAccountTranscoding;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\GeneralLedger\Services\Tax\Decimal;

abstract class AbstractStudioCsvExporter implements StudioExportStrategyInterface
{
    /**
     * @param  array<string, FiduciaryAccountTranscoding>  $transcodingMap
     * @return array{account_code: string, account_name: string, vat_code: string, cost_center_code: string}
     */
    protected function resolveLineMapping(JournalLine $line, array $transcodingMap, bool $applyTranscoding): array
    {
        $sourceCode = (string) ($line->account?->account_code ?? '');
        $sourceName = (string) ($line->account?->name ?? '');
        $sourceVat = (string) ($line->vatCode?->code ?? '');
        $sourceCostCenter = (string) ($line->costCenter?->code ?? '');

        if (! $applyTranscoding) {
            return [
                'account_code' => $sourceCode,
                'account_name' => $sourceName,
                'vat_code' => $sourceVat,
                'cost_center_code' => $sourceCostCenter,
            ];
        }

        $rule = $transcodingMap[(string) $line->account_id] ?? null;

        return [
            'account_code' => $rule !== null && trim((string) $rule->studio_account_code) !== '' ? (string) $rule->studio_account_code : $sourceCode,
            'account_name' => $rule !== null && trim((string) $rule->studio_account_name) !== '' ? (string) $rule->studio_account_name : $sourceName,
            'vat_code' => $rule !== null && trim((string) ($rule->studio_vat_code ?? '')) !== '' ? (string) $rule->studio_vat_code : $sourceVat,
            'cost_center_code' => $rule !== null && trim((string) ($rule->studio_cost_center_code ?? '')) !== '' ? (string) $rule->studio_cost_center_code : $sourceCostCenter,
        ];
    }

    /**
     * Pairs debit and credit lines within an entry into balanced double-entry booking pairs (for Abacus, Topal, Sage, DATEV).
     *
     * @param  array<string, FiduciaryAccountTranscoding>  $transcodingMap
     * @return list<array{debit_code: string, credit_code: string, amount: string, vat_code: string, currency: string, exchange_rate: string, text: string}>
     */
    protected function pairEntryLines(JournalEntry $entry, array $transcodingMap, bool $applyTranscoding, string $defaultCurrency): array
    {
        $debits = [];
        $credits = [];

        /** @var JournalLine $line */
        foreach ($entry->lines as $line) {
            $mapped = $this->resolveLineMapping($line, $transcodingMap, $applyTranscoding);
            $debitAmt = (string) ($line->functional_debit ?? $line->debit_amount);
            $creditAmt = (string) ($line->functional_credit ?? $line->credit_amount);
            $net = Decimal::sub($debitAmt, $creditAmt);
            $text = (string) ($line->description ?: $entry->description);
            $currency = (string) ($line->currency ?: $defaultCurrency);
            $rate = (string) ($line->exchange_rate ?: '1.000000');

            if (Decimal::isPositive($net)) {
                $debits[] = ['code' => $mapped['account_code'], 'amount' => $net, 'vat' => $mapped['vat_code'], 'currency' => $currency, 'rate' => $rate, 'text' => $text];
            } elseif (Decimal::isNegative($net)) {
                $credits[] = ['code' => $mapped['account_code'], 'amount' => Decimal::neg($net), 'vat' => $mapped['vat_code'], 'currency' => $currency, 'rate' => $rate, 'text' => $text];
            }
        }

        $pairs = [];
        while ($debits !== [] && $credits !== []) {
            $take = Decimal::compare($debits[0]['amount'], $credits[0]['amount']) <= 0 ? $debits[0]['amount'] : $credits[0]['amount'];
            $amount = Decimal::round2($take);

            if (! Decimal::isZero($amount)) {
                $pairs[] = [
                    'debit_code' => $debits[0]['code'],
                    'credit_code' => $credits[0]['code'],
                    'amount' => $amount,
                    'vat_code' => $debits[0]['vat'] !== '' ? $debits[0]['vat'] : $credits[0]['vat'],
                    'currency' => $debits[0]['currency'],
                    'exchange_rate' => $debits[0]['rate'],
                    'text' => $debits[0]['text'],
                ];
            }

            $debits[0]['amount'] = Decimal::sub($debits[0]['amount'], $take);
            $credits[0]['amount'] = Decimal::sub($credits[0]['amount'], $take);

            if (Decimal::isZero(Decimal::round2($debits[0]['amount']))) {
                array_shift($debits);
            }
            if (Decimal::isZero(Decimal::round2($credits[0]['amount']))) {
                array_shift($credits);
            }
        }

        return $pairs;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    protected function buildSemicolonCsv(array $headers, array $rows, bool $withBom = true): string
    {
        $lines = [implode(';', array_map(fn (string $v): string => $this->csvCell($v), $headers))];
        foreach ($rows as $row) {
            $lines[] = implode(';', array_map(fn (string $v): string => $this->csvCell($v), $row));
        }

        $payload = implode("\r\n", $lines)."\r\n";

        return $withBom ? "\xEF\xBB\xBF".$payload : $payload;
    }

    protected function csvCell(string $value): string
    {
        $clean = str_replace(["\r", "\n"], ' ', $value);
        if (str_contains($clean, ';') || str_contains($clean, '"')) {
            return '"'.str_replace('"', '""', $clean).'"';
        }

        return $clean;
    }
}
