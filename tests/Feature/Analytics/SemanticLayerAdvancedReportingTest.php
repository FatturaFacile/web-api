<?php

declare(strict_types=1);

use App\Models\Invoice;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Analytics\Models\ExportRun;
use Symfony\Component\HttpFoundation\StreamedResponse;

require_once __DIR__.'/BiTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2027-03-15 10:00:00');
    $this->tenant = biTenant('Alfa');
    $this->user = biActAs($this->tenant);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function biAdvancedRun(array $spec): TestResponse
{
    return test()->postJson('/api/v1/analytics/designer/run', ['dataset' => 'sales', 'spec' => $spec]);
}

test('filters on measures become HAVING conditions with typed operators and the same safety as dimension filters', function (): void {
    $rossi = biCustomer($this->tenant, 'Rossi');
    $verdi = biCustomer($this->tenant, 'Verdi');
    $neri = biCustomer($this->tenant, 'Neri');
    biInvoice($this->tenant, $rossi, '2027-03-05', '300.00');
    biInvoice($this->tenant, $rossi, '2027-03-06', '100.00');
    biInvoice($this->tenant, $verdi, '2027-03-07', '150.00');
    biInvoice($this->tenant, $neri, '2027-03-08', '20.00');
    $customers = static fn (array $filters): array => collect(biAdvancedRun(biSpec(['customer'], ['net_amount', 'document_count'], ['filters' => $filters, 'sort' => [['field' => 'customer', 'direction' => 'asc']]]))->assertOk()->json('data.rows'))->pluck('customer')->all();

    expect($customers([['field' => 'net_amount', 'operator' => 'gt', 'value' => '100']]))->toBe(['Rossi', 'Verdi'])
        ->and($customers([['field' => 'net_amount', 'operator' => 'gte', 'value' => 150]]))->toBe(['Rossi', 'Verdi'])
        ->and($customers([['field' => 'net_amount', 'operator' => 'lt', 'value' => '150.00']]))->toBe(['Neri'])
        ->and($customers([['field' => 'net_amount', 'operator' => 'between', 'value' => ['100', '200']]]))->toBe(['Verdi'])
        ->and($customers([['field' => 'document_count', 'operator' => 'eq', 'value' => 2]]))->toBe(['Rossi'])
        ->and($customers([['field' => 'document_count', 'operator' => 'neq', 'value' => 1]]))->toBe(['Rossi'])
        ->and($customers([['field' => 'net_amount', 'operator' => 'not_null']]))->toHaveCount(3)
        ->and($customers([['field' => 'net_amount', 'operator' => 'gt', 'value' => '100'], ['field' => 'customer', 'operator' => 'starts_with', 'value' => 'V']]))->toBe(['Verdi']);

    // The condition may use a measure that is not shown, and the total follows it.
    $total = biAdvancedRun(biSpec(['customer'], ['document_count'], ['filters' => [['field' => 'net_amount', 'operator' => 'gt', 'value' => '100']], 'limit' => 5]))->assertOk()->json('data');
    expect($total['meta']['total'])->toBe(2);
    expect(biAdvancedRun(biSpec([], ['net_amount'], ['filters' => [['field' => 'net_amount', 'operator' => 'gt', 'value' => '1000']]]))->assertOk()->json('data.rows'))->toBe([]);

    $attacks = [
        [['field' => 'net_amount', 'operator' => 'in', 'value' => [1, 2]]],
        [['field' => 'net_amount', 'operator' => 'contains', 'value' => '1']],
        [['field' => 'net_amount', 'operator' => 'gt', 'value' => '1; DROP TABLE invoices']],
        [['field' => 'net_amount', 'operator' => 'gt', 'value' => "1' OR '1'='1"]],
        [['field' => 'net_amount', 'operator' => 'gt', 'value' => 'abc']],
        [['field' => 'net_amount', 'operator' => 'gt', 'value' => ['1']]],
        [['field' => 'net_amount', 'operator' => 'between', 'value' => ['1']]],
        [['field' => 'net_amount', 'operator' => 'between', 'value' => ['1', 'x) --']]],
        [['field' => 'net_amount) > 0 OR (1=1', 'operator' => 'gt', 'value' => '1']],
        [['field' => 'SUM(total_net)', 'operator' => 'gt', 'value' => '1']],
        [['field' => 'net_amount', 'operator' => 'gt) OR (1=1', 'value' => '1']],
    ];
    $invoices = Invoice::query()->count();

    foreach ($attacks as $index => $filters) {
        biAdvancedRun(biSpec(['customer'], ['net_amount'], ['filters' => $filters]))->assertUnprocessable();
        expect(Invoice::query()->count())->toBe($invoices, "attack {$index}");
    }
});

test('a period comparison can be grouped by date: every previous bucket lines up with the current one', function (): void {
    $rossi = biCustomer($this->tenant, 'Rossi');
    biInvoice($this->tenant, $rossi, '2026-01-10', '100.00');
    biInvoice($this->tenant, $rossi, '2026-02-10', '40.00');
    biInvoice($this->tenant, $rossi, '2027-01-10', '150.00');
    biInvoice($this->tenant, $rossi, '2027-03-10', '70.00');

    $result = biAdvancedRun(biSpec(['date:month'], ['net_amount'], ['period' => ['from' => '2027-01-01', 'to' => '2027-03-31'], 'compare' => 'previous_year', 'sort' => [['field' => 'date', 'direction' => 'asc']]]))->assertOk()->json('data');
    $byMonth = collect($result['rows'])->keyBy('date');
    expect($result['meta']['comparison_period'])->toBe(['from' => '2026-01-01', 'to' => '2026-03-31'])
        ->and(array_keys($byMonth->all()))->toBe(['2027-01-01', '2027-03-01'])
        ->and($byMonth['2027-01-01']['net_amount__previous'])->toBe('100.00')
        ->and($byMonth['2027-01-01']['net_amount__change'])->toBe('50.00')
        ->and($byMonth['2027-01-01']['net_amount__change_percent'])->toBe('50.00')
        ->and($byMonth['2027-03-01']['net_amount__previous'])->toBeNull();

    // Previous period: the two months before, moved forward by their length.
    $result = biAdvancedRun(biSpec(['customer', 'date:month'], ['net_amount'], ['period' => ['from' => '2027-02-01', 'to' => '2027-03-31'], 'compare' => 'previous_period']))->assertOk()->json('data');
    expect($result['meta']['comparison_period'])->toBe(['from' => '2026-12-04', 'to' => '2027-01-31'])
        ->and($result['rows'])->toHaveCount(1)
        ->and($result['rows'][0]['date'])->toBe('2027-03-01');

    // A filter on a measure selects the current rows, not the previous values.
    $filtered = biAdvancedRun(biSpec(['date:month'], ['net_amount'], ['period' => ['from' => '2027-01-01', 'to' => '2027-03-31'], 'compare' => 'previous_year', 'filters' => [['field' => 'net_amount', 'operator' => 'gt', 'value' => '120']]]))->assertOk()->json('data.rows');
    expect($filtered)->toHaveCount(1)->and($filtered[0]['net_amount__previous'])->toBe('100.00');

    $yearly = biAdvancedRun(biSpec(['date:year'], ['net_amount'], ['period' => ['from' => '2027-01-01', 'to' => '2027-12-31'], 'compare' => 'previous_year']))->assertOk()->json('data.rows');
    expect($yearly[0]['date'])->toBe('2027-01-01')->and($yearly[0]['net_amount__previous'])->toBe('140.00');
});

test('exports are streamed in chunks over more rows than one chunk, with the same cap, headers and audit', function (): void {
    $customer = biCustomer($this->tenant, 'Rossi');
    $now = now()->toDateTimeString();
    $rows = [];

    foreach (range(1, 2300) as $number) {
        $rows[] = [
            'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'contact_id' => $customer->id, 'invoice_type' => 'standard', 'invoice_number' => sprintf('N%05d', $number),
            'date' => '2027-03-05', 'due_date' => '2027-03-05', 'currency' => 'EUR', 'total_net' => '1.00', 'total_tax' => '0', 'total_gross' => '1.00', 'total_paid' => '0',
            'payment_status' => 'unpaid', 'status' => 'posted', 'posted_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ];
    }

    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('invoices')->insert($chunk);
    }

    $spec = biSpec(['invoice_number'], ['net_amount'], ['sort' => [['field' => 'invoice_number', 'direction' => 'asc']]]);

    foreach (['csv', 'excel_csv'] as $format) {
        $response = $this->postJson('/api/v1/analytics/designer/export', ['dataset' => 'sales', 'spec' => $spec, 'format' => $format])->assertOk();
        expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class)
            ->and($response->headers->get('X-Report-Truncated'))->toBe('false');
        $lines = explode("\r\n", rtrim($response->streamedContent(), "\r\n"));
        expect($lines)->toHaveCount(2301)->and($lines[1])->toContain('N00001')->and($lines[2300])->toContain('N02300');
    }

    $xlsx = $this->postJson('/api/v1/analytics/designer/export', ['dataset' => 'sales', 'spec' => $spec, 'format' => 'xlsx'])->assertOk();
    $sheets = biReadXlsx($xlsx->streamedContent());
    $data = array_values($sheets)[0];
    expect($data)->toHaveCount(2301)->and($data[2300][0])->toBe('N02300')->and($data[2300][1])->toBe(1)
        ->and(array_column($sheets['Parameters'], 1, 0)['Rows'])->toBe('2300');

    config(['analytics.reporting.max_rows' => 1500]);
    $capped = $this->postJson('/api/v1/analytics/designer/export', ['dataset' => 'sales', 'spec' => $spec, 'format' => 'csv'])->assertOk();
    expect($capped->headers->get('X-Report-Truncated'))->toBe('true')->and(substr_count($capped->streamedContent(), "\r\n"))->toBe(1501);

    $run = ExportRun::query()->where('format', 'csv')->orderBy('created_at')->first();
    expect($run->size_bytes)->toBeGreaterThan(0)->and($run->sha256)->toHaveLength(64)->and($run->row_count)->toBe(2300);
    expect(AuditLedgerEntry::query()->where('action', 'ANALYTICS_REPORT_EXPORTED')->count())->toBe(4);
});
