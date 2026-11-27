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
 * Italian TeamSystem Gamma / Alyante / Bridge Prima Nota format.
 */
final class TeamSystemExporter extends AbstractStudioCsvExporter
{
    public function format(): StudioExportFormat
    {
        return StudioExportFormat::TeamSystem;
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
            'TipoRecord',
            'DataRegistrazione',
            'NumeroProtocollo',
            'DataDocumento',
            'NumeroDocumento',
            'Causale',
            'ContoStudio',
            'ContoOriginale',
            'DareAvere',
            'Importo',
            'CodiceIVA',
            'ImponibileIVA',
            'ImpostaIVA',
            'Valuta',
            'Descrizione',
        ];
        $rows = [];

        /** @var JournalEntry $entry */
        foreach ($entries as $entry) {
            /** @var JournalLine $line */
            foreach ($entry->lines as $line) {
                $mapped = $this->resolveLineMapping($line, $transcodingMap, $applyTranscoding);
                $debit = (string) ($line->functional_debit ?? $line->debit_amount);
                $credit = (string) ($line->functional_credit ?? $line->credit_amount);
                $isDebit = Decimal::isPositive($debit);

                $rows[] = [
                    'PN',
                    $entry->posting_date?->format('d/m/Y') ?? '',
                    (string) ($entry->journal_number ?? $entry->entry_number),
                    $entry->document_date?->format('d/m/Y') ?? '',
                    (string) ($entry->getAttribute('document_number') ?: $entry->entry_number),
                    strtoupper((string) $entry->journal_type),
                    $mapped['account_code'],
                    (string) ($line->account->account_code ?? ''),
                    $isDebit ? 'D' : 'A',
                    Decimal::round2($isDebit ? $debit : $credit),
                    $mapped['vat_code'],
                    Decimal::round2((string) ($line->vat_base_amount ?? '0')),
                    Decimal::round2((string) ($line->vat_tax_amount ?? '0')),
                    $currency,
                    mb_substr((string) ($line->description ?: $entry->description), 0, 80),
                ];
            }
        }

        return [
            'content' => $this->buildSemicolonCsv($headers, $rows),
            'file_name' => "teamsystem_prima_nota_{$from->format('Ymd')}_{$to->format('Ymd')}.csv",
            'mime_type' => $this->format()->mimeType(),
            'rows_count' => count($rows),
        ];
    }
}
