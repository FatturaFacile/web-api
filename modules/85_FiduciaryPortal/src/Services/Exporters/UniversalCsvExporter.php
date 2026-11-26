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
 * Universal ASCII / CSV double-entry journal export format.
 */
final class UniversalCsvExporter extends AbstractStudioCsvExporter
{
    public function format(): StudioExportFormat
    {
        return StudioExportFormat::UniversalCsv;
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
            'Company',
            'EntryNumber',
            'PostingDate',
            'DocumentDate',
            'DocumentNumber',
            'LineNumber',
            'SourceAccountCode',
            'StudioAccountCode',
            'StudioAccountName',
            'VatCode',
            'CostCenterCode',
            'FunctionalCurrency',
            'FunctionalDebit',
            'FunctionalCredit',
            'TransactionCurrency',
            'TransactionDebit',
            'TransactionCredit',
            'ExchangeRate',
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
                    (string) $tenant->name,
                    (string) $entry->entry_number,
                    $entry->posting_date?->toDateString() ?? '',
                    $entry->document_date?->toDateString() ?? '',
                    (string) ($entry->getAttribute('document_number') ?: ''),
                    (string) $line->line_number,
                    (string) ($line->account?->account_code ?? ''),
                    $mapped['account_code'],
                    $mapped['account_name'],
                    $mapped['vat_code'],
                    $mapped['cost_center_code'],
                    $currency,
                    (string) ($line->functional_debit ?? $line->debit_amount),
                    (string) ($line->functional_credit ?? $line->credit_amount),
                    (string) ($line->currency ?: $currency),
                    (string) $line->debit_amount,
                    (string) $line->credit_amount,
                    (string) ($line->exchange_rate ?: '1.000000'),
                    $entry->getAttribute('fiduciary_verified') ? '1' : '0',
                    (string) ($line->description ?: $entry->description),
                ];
            }
        }

        return [
            'content' => $this->buildSemicolonCsv($headers, $rows),
            'file_name' => "universal_journal_{$from->format('Ymd')}_{$to->format('Ymd')}.csv",
            'mime_type' => $this->format()->mimeType(),
            'rows_count' => count($rows),
        ];
    }
}
