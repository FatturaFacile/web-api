<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Services\Exporters;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\FiduciaryPortal\Domain\Enums\StudioExportFormat;
use Modules\GeneralLedger\Models\JournalEntry;

/**
 * Swiss Sage 50 Extra (.taf) booking import format.
 */
final class Sage50ExtraExporter extends AbstractStudioCsvExporter
{
    public function format(): StudioExportFormat
    {
        return StudioExportFormat::Sage50Extra;
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
        $headers = ['BlgNr', 'Datum', 'Kto', 'Gkto', 'SollHaben', 'Betrag', 'FWBetrag', 'Waehrung', 'Kurs', 'SteuerCode', 'Buchungstext'];
        $rows = [];

        /** @var JournalEntry $entry */
        foreach ($entries as $entry) {
            foreach ($this->pairEntryLines($entry, $transcodingMap, $applyTranscoding, $currency) as $pair) {
                $rows[] = [
                    (string) ($entry->getAttribute('document_number') ?: $entry->entry_number),
                    $entry->posting_date?->format('d.m.Y') ?? $from->format('d.m.Y'),
                    $pair['debit_code'],
                    $pair['credit_code'],
                    'S',
                    $pair['amount'],
                    $pair['amount'],
                    $pair['currency'],
                    $pair['exchange_rate'],
                    $pair['vat_code'],
                    mb_substr($pair['text'], 0, 80),
                ];
            }
        }

        return [
            'content' => $this->buildSemicolonCsv($headers, $rows, false),
            'file_name' => "sage50_extra_{$from->format('Ymd')}_{$to->format('Ymd')}.taf",
            'mime_type' => $this->format()->mimeType(),
            'rows_count' => count($rows),
        ];
    }
}
