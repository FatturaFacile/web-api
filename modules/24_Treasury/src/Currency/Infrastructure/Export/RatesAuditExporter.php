<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Infrastructure\Export;

use App\Models\ExchangeRate;
use App\Services\Security\CsvFormulaNeutralizer;
use Illuminate\Database\Eloquent\Builder;
use Modules\DataExport\Services\ExcelCsvWriter;
use Modules\Treasury\Currency\Infrastructure\Persistence\ExchangeRateMapper;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * The audit export of the exchange rates of a period: every rate the company saw (official and its own), superseded versions
 * included so that a correction can be followed, as CSV (UTF-8 BOM, semicolon, formula-injection safe) or as a native .xlsx
 * workbook. Rates are written as exact decimal strings, never as floats.
 */
final readonly class RatesAuditExporter
{
    /** @var list<string> */
    public const array Columns = ['base', 'quote', 'rate', 'rate_type', 'source', 'valid_on', 'is_derived', 'version', 'superseded', 'owner', 'reason', 'contract_reference', 'recorded_at'];

    public function __construct(private ExcelCsvWriter $writer) {}

    /**
     * @return array{filename: string, content: string, content_type: string}
     */
    public function export(string $tenantId, string $from, string $to, string $format = 'csv'): array
    {
        $rows = $this->rows($tenantId, $from, $to);
        $name = "exchange-rates-{$from}-{$to}.".($format === 'xlsx' ? 'xlsx' : 'csv');

        if ($format === 'xlsx') {
            return ['filename' => $name, 'content' => $this->xlsx($rows), 'content_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        }

        return ['filename' => $name, 'content' => $this->writer->write(self::Columns, $rows), 'content_type' => 'text/csv; charset=UTF-8'];
    }

    /**
     * @return list<list<string>>
     */
    private function rows(string $tenantId, string $from, string $to): array
    {
        $records = ExchangeRate::query()
            ->withoutGlobalScopes()
            ->where(static function (Builder $scope) use ($tenantId): void {
                $scope->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
            })
            ->whereDate('effective_date', '>=', $from)
            ->whereDate('effective_date', '<=', $to)
            ->orderBy('effective_date')
            ->orderBy('source')
            ->orderBy('target')
            ->orderBy('rate_type')
            ->orderBy('version')
            ->get();

        return $records->map(static fn (ExchangeRate $record): array => [
            (string) $record->source,
            (string) $record->target,
            (string) $record->rate,
            (string) $record->rate_type,
            (string) $record->rate_source,
            ExchangeRateMapper::day($record->getRawOriginal('effective_date')),
            $record->is_derived ? 'yes' : 'no',
            (string) $record->version,
            $record->superseded_by === null ? 'no' : 'yes',
            $record->tenant_id === null ? 'official' : 'company',
            (string) $record->reason,
            (string) $record->contract_reference,
            $record->created_at?->toIso8601String() ?? '',
        ])->all();
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function xlsx(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fxr');

        if ($path === false) {
            throw new RuntimeException('Cannot create the temporary workbook file.');
        }

        try {
            $writer = new Writer;
            $writer->openToFile($path);
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Exchange rates');
            $sheet->setSheetView(new SheetView(freezeRow: 2));
            $writer->addRow(Row::fromValuesWithStyle(self::Columns, (new Style)->withFontBold(true)->withBackgroundColor('E7E6E6')));

            foreach ($rows as $row) {
                $writer->addRow(new Row(array_map(static fn (string $value): StringCell => new StringCell(CsvFormulaNeutralizer::neutralize($value), null), $row)));
            }

            $writer->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }
}
