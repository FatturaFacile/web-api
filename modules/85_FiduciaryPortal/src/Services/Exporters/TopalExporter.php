<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Services\Exporters;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\FiduciaryPortal\Domain\Enums\StudioExportFormat;
use Modules\GeneralLedger\Models\JournalEntry;

/**
 * Swiss Topal Finanzbuchhaltung CSV booking format.
 */
final class TopalExporter extends AbstractStudioCsvExporter
{
    public function format(): StudioExportFormat
    {
        return StudioExportFormat::Topal;
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
        $headers = ['BelegNr', 'Datum', 'Konto', 'Gegenkonto', 'Betrag', 'SH', 'MWSTCode', 'Waehrung', 'Kurs', 'Text'];
        $rows = [];

        /** @var JournalEntry $entry */
        foreach ($entries as $entry) {
            foreach ($this->pairEntryLines($entry, $transcodingMap, $applyTranscoding, $currency) as $pair) {
                $rows[] = [
                    (string) ($entry->getAttribute('document_number') ?: $entry->entry_number),
                    $entry->posting_date?->format('d.m.Y') ?? $from->format('d.m.Y'),
                    $pair['debit_code'],
                    $pair['credit_code'],
                    $pair['amount'],
                    'S',
                    $pair['vat_code'],
                    $pair['currency'],
                    $pair['exchange_rate'],
                    mb_substr($pair['text'], 0, 80),
                ];
            }
        }

        return [
            'content' => $this->buildSemicolonCsv($headers, $rows),
            'file_name' => "topal_fibu_{$from->format('Ymd')}_{$to->format('Ymd')}.csv",
            'mime_type' => $this->format()->mimeType(),
            'rows_count' => count($rows),
        ];
    }
}
