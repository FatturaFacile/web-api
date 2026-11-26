<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Services\Exporters;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\FiduciaryPortal\Domain\Enums\StudioExportFormat;
use Modules\GeneralLedger\Models\JournalEntry;

/**
 * DATEV / DATEV Koinos EXTF 700 Buchungsstapel format with studio account transcoding support.
 */
final class DatevKoinosExporter extends AbstractStudioCsvExporter
{
    public const string VERSION = '700';

    /** @var list<string> */
    public const array COLUMNS = [
        'Umsatz (ohne Soll/Haben-Kz)',
        'Soll/Haben-Kennzeichen',
        'WKZ Umsatz',
        'Kurs',
        'Basis-Umsatz',
        'WKZ Basis-Umsatz',
        'Konto',
        'Gegenkonto (ohne BU-Schlüssel)',
        'BU-Schlüssel',
        'Belegdatum',
        'Belegfeld 1',
        'Belegfeld 2',
        'Skonto',
        'Buchungstext',
    ];

    public function format(): StudioExportFormat
    {
        return StudioExportFormat::DatevKoinos;
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
        $consultantNumber = (int) ($options['consultant_number'] ?? 1);
        $clientNumber = (int) ($options['client_number'] ?? 1);
        $accountLength = (int) ($options['account_length'] ?? 4);

        $header = implode(';', [
            $this->q('EXTF'),
            self::VERSION,
            '21',
            $this->q('Buchungsstapel'),
            '13',
            now()->format('YmdHis').'000',
            '',
            $this->q('FF'),
            $this->q(''),
            $this->q(''),
            (string) $consultantNumber,
            (string) $clientNumber,
            $from->startOfYear()->format('Ymd'),
            (string) $accountLength,
            $from->format('Ymd'),
            $to->format('Ymd'),
            $this->q('Fiduciary Portal Export'),
            $this->q(''),
            '1',
            '0',
            '0',
            $this->q($currency),
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
        ]);

        $lines = [
            $header,
            implode(';', array_map(fn (string $c): string => $this->q($c), self::COLUMNS)),
        ];

        $rowsCount = 0;

        /** @var JournalEntry $entry */
        foreach ($entries as $entry) {
            foreach ($this->pairEntryLines($entry, $transcodingMap, $applyTranscoding, $currency) as $pair) {
                $debitNumeric = preg_replace('/\D+/', '', $pair['debit_code']) ?: '9999';
                $creditNumeric = preg_replace('/\D+/', '', $pair['credit_code']) ?: '9999';
                $buKey = preg_replace('/\D+/', '', $pair['vat_code']) ?: '';

                $lines[] = implode(';', [
                    str_replace('.', ',', $pair['amount']),
                    $this->q('S'),
                    $this->q($currency),
                    '',
                    '',
                    '',
                    $debitNumeric,
                    $creditNumeric,
                    $buKey,
                    ($entry->document_date ?? $entry->posting_date)?->format('dm') ?? $from->format('dm'),
                    $this->q(mb_substr((string) ($entry->getAttribute('document_number') ?: $entry->entry_number), 0, 36)),
                    '',
                    '',
                    $this->q(mb_substr($pair['text'], 0, 60)),
                ]);
                $rowsCount++;
            }
        }

        $utf8 = implode("\r\n", $lines)."\r\n";

        return [
            'content' => (string) mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8'),
            'file_name' => "EXTF_DATEV_Koinos_{$from->format('Ymd')}_{$to->format('Ymd')}.csv",
            'mime_type' => $this->format()->mimeType(),
            'rows_count' => $rowsCount,
        ];
    }

    private function q(string $value): string
    {
        return '"'.str_replace('"', '""', trim((string) preg_replace('/[\r\n;]+/', ' ', $value))).'"';
    }
}
