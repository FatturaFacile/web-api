<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Services\Exporters;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\FiduciaryPortal\Domain\Enums\StudioExportFormat;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Italian Zucchetti Ago / AdHoc / Omnia Prima Nota record format (H header / D detail / V VAT).
 */
final class ZucchettiExporter extends AbstractStudioCsvExporter
{
    public function format(): StudioExportFormat
    {
        return StudioExportFormat::Zucchetti;
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
        $headers = ['TipoRiga', 'PartitaIVA', 'DataReg', 'NumReg', 'DataDoc', 'NumDoc', 'ContoStudio', 'Segno', 'Importo', 'CodIVA', 'Imponibile', 'Imposta', 'Divisa', 'Descrizione'];
        $rows = [];

        /** @var JournalEntry $entry */
        foreach ($entries as $entry) {
            $rows[] = [
                'H',
                (string) ($tenant->vat_number ?: $tenant->fiscal_code ?: $tenant->name),
                $entry->posting_date?->format('Ymd') ?? '',
                (string) $entry->entry_number,
                $entry->document_date?->format('Ymd') ?? '',
                (string) ($entry->getAttribute('document_number') ?: $entry->entry_number),
                '',
                '',
                Decimal::round2((string) ($entry->total_amount ?? '0')),
                '',
                '0.00',
                '0.00',
                $currency,
                mb_substr((string) $entry->description, 0, 80),
            ];

            /** @var JournalLine $line */
            foreach ($entry->lines as $line) {
                $mapped = $this->resolveLineMapping($line, $transcodingMap, $applyTranscoding);
                $debit = (string) ($line->functional_debit ?? $line->debit_amount);
                $credit = (string) ($line->functional_credit ?? $line->credit_amount);
                $isDebit = Decimal::isPositive($debit);

                $rows[] = [
                    'D',
                    (string) ($tenant->vat_number ?: ''),
                    $entry->posting_date?->format('Ymd') ?? '',
                    (string) $entry->entry_number,
                    $entry->document_date?->format('Ymd') ?? '',
                    (string) ($entry->getAttribute('document_number') ?: $entry->entry_number),
                    $mapped['account_code'],
                    $isDebit ? 'D' : 'A',
                    Decimal::round2($isDebit ? $debit : $credit),
                    $mapped['vat_code'],
                    Decimal::round2((string) ($line->vat_base_amount ?? '0')),
                    Decimal::round2((string) ($line->vat_tax_amount ?? '0')),
                    (string) ($line->currency ?: $currency),
                    mb_substr((string) ($line->description ?: $entry->description), 0, 80),
                ];
            }
        }

        return [
            'content' => $this->buildSemicolonCsv($headers, $rows),
            'file_name' => "zucchetti_prima_nota_{$from->format('Ymd')}_{$to->format('Ymd')}.csv",
            'mime_type' => $this->format()->mimeType(),
            'rows_count' => count($rows),
        ];
    }
}
