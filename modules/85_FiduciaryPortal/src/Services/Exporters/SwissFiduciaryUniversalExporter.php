<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Services\Exporters;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\FiduciaryPortal\Domain\Enums\StudioExportFormat;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;

/**
 * Swiss Fiduciary Universal Accounting Format (KMU / MWST / multi-currency ledger layout).
 */
final class SwissFiduciaryUniversalExporter extends AbstractStudioCsvExporter
{
    public function format(): StudioExportFormat
    {
        return StudioExportFormat::SwissFiduciaryUniversal;
    }

    public function generate(
        Tenant $tenant,
        Collection $entries,
        array $transcodingMap,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $currency,
        array $options = [],
    ): array {
        $applyTranscoding = (bool) ($options['apply_transcoding'] ?? true);
        $headers = [
            'UID',
            'EntryNumber',
            'PostingDate',
            'DocumentDate',
            'DocumentNumber',
            'LineNumber',
            'KmuAccountCode',
            'StudioAccountCode',
            'StudioAccountName',
            'MwstCode',
            'MwstRatePercent',
            'MwstBaseAmount',
            'MwstTaxAmount',
            'FunctionalCurrency',
            'FunctionalDebit',
            'FunctionalCredit',
            'TransactionCurrency',
            'TransactionDebit',
            'TransactionCredit',
            'ExchangeRate',
            'CostCenter',
            'FiduciaryVerified',
            'Description',
        ];
        $rows = [];

        /** @var JournalEntry $entry */
        foreach ($entries as $entry) {
            /** @var JournalLine $line */
            foreach ($entry->lines as $line) {
                $mapped = $this->resolveLineMapping($line, $transcodingMap, $applyTranscoding);
                $rows[] = [
                    (string) ($tenant->uid_number ?: $tenant->name),
                    (string) $entry->entry_number,
                    $entry->posting_date?->toDateString() ?? '',
                    $entry->document_date?->toDateString() ?? '',
                    (string) ($entry->getAttribute('document_number') ?: ''),
                    (string) $line->line_number,
                    (string) ($line->account?->account_code ?? ''),
                    $mapped['account_code'],
                    $mapped['account_name'],
                    $mapped['vat_code'],
                    (string) ($line->vat_rate_percent ?? ''),
                    (string) ($line->vat_base_amount ?? '0.0000'),
                    (string) ($line->vat_tax_amount ?? '0.0000'),
                    $currency,
                    (string) ($line->functional_debit ?? $line->debit_amount),
                    (string) ($line->functional_credit ?? $line->credit_amount),
                    (string) ($line->currency ?: $currency),
                    (string) $line->debit_amount,
                    (string) $line->credit_amount,
                    (string) ($line->exchange_rate ?: '1.000000'),
                    $mapped['cost_center_code'],
                    $entry->getAttribute('fiduciary_verified') ? '1' : '0',
                    (string) ($line->description ?: $entry->description),
                ];
            }
        }

        return [
            'content' => $this->buildSemicolonCsv($headers, $rows),
            'file_name' => "swiss_fiduciary_universal_{$from->format('Ymd')}_{$to->format('Ymd')}.csv",
            'mime_type' => $this->format()->mimeType(),
            'rows_count' => count($rows),
        ];
    }
}
