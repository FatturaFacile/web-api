<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Modules\Analytics\Models\ExportRun;
use Modules\Analytics\Services\ReportExportFormatter;

require_once __DIR__.'/BiTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2027-03-15 10:00:00');
    $this->tenant = biTenant('Alfa');
    $this->user = biActAs($this->tenant);
    biInvoice($this->tenant, biCustomer($this->tenant, 'Alpha Srl'), '2026-02-10', '1234.50');
    biInvoice($this->tenant, biCustomer($this->tenant, 'Beta Srl'), '2026-03-01', '1000000.25');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function exportOf(string $format, ?string $locale, array $extra = []): TestResponse
{
    return test()->postJson('/api/v1/analytics/designer/export', [
        'dataset' => 'sales',
        'spec' => biSpec(['customer', 'date:month'], ['net_amount', 'document_count'], ['sort' => [['field' => 'customer', 'direction' => 'asc']]]),
        'format' => $format,
        'locale' => $locale,
        ...$extra,
    ]);
}

test('csv follows the regional number, date and delimiter conventions and translates the headers', function (): void {
    $swiss = exportOf('csv', 'it-CH')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();
    expect($swiss)->toStartWith("\"Cliente\";\"Data fattura\";\"Importo netto\";\"Documenti\"\r\n")
        ->and($swiss)->toContain("\"Alpha Srl\";\"01.02.2026\";\"1'234.50\";\"1\"")
        ->and($swiss)->toContain("\"1'000'000.25\"");

    $italian = exportOf('csv', 'it-IT')->streamedContent();
    expect($italian)->toContain('"Alpha Srl";"01/02/2026";"1.234,50";"1"')->and($italian)->toContain('"1.000.000,25"');

    $german = exportOf('csv', 'de-CH')->streamedContent();
    expect($german)->toStartWith("\"Kunde\";\"Rechnungsdatum\";\"Nettobetrag\";\"Belege\"\r\n")->and($german)->toContain("\"01.02.2026\";\"1'234.50\"");

    $french = exportOf('csv', 'fr-CH')->streamedContent();
    expect($french)->toStartWith("\"Client\";\"Date de facture\";\"Montant net\";\"Documents\"\r\n");

    $english = exportOf('csv', 'en')->streamedContent();
    expect($english)->toStartWith("\"Customer\",\"Invoice date\",\"Net amount\",\"Documents\"\r\n")
        ->and($english)->toContain('"Alpha Srl","2026-02-01","1,234.50","1"')
        ->and($english)->toContain('"1,000,000.25"');

    // The Excel flavour adds the byte order mark.
    expect(exportOf('excel_csv', 'it-IT')->streamedContent())->toStartWith("\xEF\xBB\xBF\"Cliente\";");

    // Without a locale nothing changes: stable keys and raw values.
    expect(exportOf('csv', null)->streamedContent())->toStartWith("\"customer\",\"date\",\"net_amount\",\"document_count\"\r\n")->and(exportOf('csv', null)->streamedContent())->toContain('"1234.50"');
});

test('xlsx is a typed workbook with translated headers, regional dates and a native sum row for the additive measures', function (): void {
    $sheets = biReadXlsx(exportOf('xlsx', 'it-CH')->streamedContent());
    $data = $sheets[array_key_first($sheets)];

    expect($data[0])->toBe(['Cliente', 'Data fattura', 'Importo netto', 'Documenti'])
        ->and($data[1][0])->toBe('Alpha Srl')
        ->and($data[1][2])->toBe(1234.5)
        ->and($data[1][3])->toBe(1)
        ->and($data[3][0])->toBe('Totale generale')
        ->and((string) $data[3][2])->toContain('SUM(C2:C3)')
        ->and((string) $data[3][3])->toContain('SUM(D2:D3)');
});

test('json carries the metadata, translated columns, rows and totals', function (): void {
    $payload = exportOf('json', 'de-CH')->assertOk()->assertHeader('Content-Type', 'application/json')->streamedContent();
    $json = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

    expect($json['meta']['dataset'])->toBe('sales')
        ->and($json['meta']['locale'])->toBe('de-CH')
        ->and($json['meta']['row_count'])->toBe(2)
        ->and(array_column($json['columns'], 'label'))->toBe(['Kunde', 'Rechnungsdatum', 'Nettobetrag', 'Belege'])
        ->and($json['rows'][0]['net_amount'])->toBe('1234.50')
        ->and($json['totals']['net_amount'])->toBe('1001234.750000')
        ->and($json['totals']['document_count'])->toBe('2.000000');
});

test('pdf is a real document, flat or pivot, with a chart when the pivot has one', function (): void {
    $flat = exportOf('pdf', 'it-CH')->assertOk()->assertHeader('Content-Type', 'application/pdf')->streamedContent();
    expect($flat)->toStartWith('%PDF-')->and(strlen($flat))->toBeGreaterThan(1500);

    $pivot = test()->postJson('/api/v1/analytics/designer/export', [
        'dataset' => 'sales',
        'spec' => biSpec([], ['net_amount']),
        'pivot' => ['rows' => ['customer'], 'columns' => ['date:year']],
        'chart_config' => ['enabled' => true, 'chartType' => 'donut', 'xAxis' => 'customer', 'series' => ['net_amount']],
        'format' => 'pdf',
        'locale' => 'it-IT',
    ])->assertOk()->streamedContent();
    expect($pivot)->toStartWith('%PDF-');
});

test('a pivot export has subtotals and totals in every format and never a sum over its own totals', function (): void {
    $body = ['dataset' => 'sales', 'spec' => biSpec([], ['net_amount']), 'pivot' => ['rows' => ['customer', 'date:year'], 'columns' => []], 'locale' => 'it-IT'];

    $csv = test()->postJson('/api/v1/analytics/designer/export', [...$body, 'format' => 'csv'])->assertOk()->streamedContent();
    expect($csv)->toStartWith("\"Cliente\";\"Data fattura\";\"Importo netto\"\r\n")
        ->and($csv)->toContain('"Alpha Srl";"01/01/2026";"1.234,50"')
        ->and($csv)->toContain('"Alpha Srl";"Subtotale";"1.234,50"')
        ->and($csv)->toContain('"Totale generale";"";"1.001.234,75"');

    $sheets = biReadXlsx(test()->postJson('/api/v1/analytics/designer/export', [...$body, 'format' => 'xlsx'])->assertOk()->streamedContent());
    $rows = $sheets[array_key_first($sheets)];
    expect(array_column($rows, 0))->toContain('Totale generale')
        ->and(collect($rows)->flatten()->filter(fn ($cell): bool => is_string($cell) && str_contains($cell, 'SUM('))->all())->toBe([]);
});

test('every export is recorded in the execution log with status, duration, rows and locale, and audited', function (): void {
    exportOf('csv', 'fr-CH')->streamedContent();
    $run = ExportRun::query()->latest()->firstOrFail();

    expect($run->status)->toBe('completed')
        ->and($run->locale)->toBe('fr-CH')
        ->and($run->row_count)->toBe(2)
        ->and($run->duration_ms)->not->toBeNull()
        ->and($run->size_bytes)->toBeGreaterThan(0);

    $logs = $this->getJson('/api/v1/analytics/execution-logs')->assertOk()->json('data');
    expect($logs[0]['status'])->toBe('completed')->and($logs[0]['locale'])->toBe('fr-CH');

    // Another member sees none of it, an administrator sees all.
    biActAs($this->tenant, 'accountant');
    expect($this->getJson('/api/v1/analytics/execution-logs')->json('data'))->toBe([]);
});

test('unknown formats and locales are refused', function (): void {
    exportOf('docx', 'en')->assertUnprocessable();
    exportOf('csv', 'xx-XX')->assertUnprocessable();
});

test('exports stream 100000 rows to csv and xlsx without holding them in memory', function (): void {
    $formatter = app(ReportExportFormatter::class);
    $columns = [
        ['key' => 'customer', 'label' => 'Customer', 'kind' => 'dimension', 'type' => 'string'],
        ['key' => 'date', 'label' => 'Invoice date', 'kind' => 'dimension', 'type' => 'date'],
        ['key' => 'net_amount', 'label' => 'Net amount', 'kind' => 'measure', 'unit' => 'currency', 'additive' => true],
        ['key' => 'document_count', 'label' => 'Documents', 'kind' => 'measure', 'unit' => 'count', 'additive' => true],
    ];
    $rows = static function (): Generator {
        for ($i = 1; $i <= 100_000; $i++) {
            yield ['customer' => 'Customer '.$i, 'date' => '2026-'.str_pad((string) (($i % 12) + 1), 2, '0', STR_PAD_LEFT).'-15', 'net_amount' => (string) ($i * 3).'.25', 'document_count' => $i % 7];
        }
    };
    $meta = ['row_count' => 100_000, 'truncated' => false, 'period' => null, 'comparison_period' => null];
    $limit = 64 * 1024 * 1024;

    foreach (['csv', 'xlsx'] as $format) {
        $context = ['sheet_name' => 'Benchmark', 'dataset' => 'sales', 'report' => null, 'spec' => biSpec(['customer'], ['net_amount']), 'generated_at' => now(), 'locale' => 'it-CH'];
        $path = tempnam(sys_get_temp_dir(), 'bench');
        $handle = fopen($path, 'wb');
        gc_collect_cycles();
        $before = memory_get_usage(true);
        memory_reset_peak_usage();

        $formatter->stream($columns, $rows(), $meta, $context, $format, static function (string $chunk) use ($handle): void {
            fwrite($handle, $chunk);
        });

        $growth = memory_get_peak_usage(true) - $before;
        fclose($handle);
        $size = filesize($path);
        unlink($path);

        expect($size)->toBeGreaterThan(1_000_000, $format)
            ->and($growth)->toBeLessThan($limit, "{$format} peaked {$growth} bytes above the start");
    }
});
