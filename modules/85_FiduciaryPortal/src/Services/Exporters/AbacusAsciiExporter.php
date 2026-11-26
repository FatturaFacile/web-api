<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Services\Exporters;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\FiduciaryPortal\Domain\Enums\StudioExportFormat;
use Modules\GeneralLedger\Models\JournalEntry;

/**
 * Swiss Abacus ASCII / CSV journal import format.
 */
final class AbacusAsciiExporter extends AbstractStudioCsvExporter
{
    public function format(): StudioExportFormat
    {
        return StudioExportFormat::AbacusAscii;
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
        $headers = ['Version', 'Date', 'Account', 'ContraAccount', 'Amount', 'DebitCredit', 'Currency', 'ExchangeRate', 'TaxCode', 'DocNumber', 'Description'];
        $rows = [];

        /** @var JournalEntry $entry */
        foreach ($entries as $entry) {
            foreach ($this->pairEntryLines($entry, $transcodingMap, $applyTranscoding, $currency) as $pair) {
                $rows[] = [
                    'ABA2',
                    $entry->posting_date?->format('d.m.Y') ?? $from->format('d.m.Y'),
                    $pair['debit_code'],
                    $pair['credit_code'],
                    $pair['amount'],
                    'S',
                    $pair['currency'],
                    $pair['exchange_rate'],
                    $pair['vat_code'],
                    (string) ($entry->getAttribute('document_number') ?: $entry->entry_number),
                    mb_substr($pair['text'], 0, 80),
                ];
            }
        }

        return [
            'content' => $this->buildSemicolonCsv($headers, $rows, false),
            'file_name' => "abacus_ascii_{$from->format('Ymd')}_{$to->format('Ymd')}.txt",
            'mime_type' => $this->format()->mimeType(),
            'rows_count' => count($rows),
        ];
    }
}
