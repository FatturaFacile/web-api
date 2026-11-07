<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Modules\FiscalRetail\Models\CommercialDocument;

require_once __DIR__.'/ComplianceHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2027-04-15 10:00:00');
    $this->tenant = complianceTenant();
    actingAsMember($this->tenant);
});

function issueDocument(array $lines = []): array
{
    return test()->postJson('/api/v1/fiscal-retail/commercial-documents', [
        'payment_method' => 'cash',
        'lines' => $lines ?: [
            ['description' => 'Pane', 'quantity' => 2, 'unit_price' => '10.00', 'tax_rate' => 22],
            ['description' => 'Libro', 'quantity' => 1, 'unit_price' => '5.00', 'tax_rate' => 4],
        ],
    ])->assertCreated()->json('data');
}

test('documents get a progressive number per tenant and are not reported as transmitted', function (): void {
    $first = issueDocument();
    $second = issueDocument();

    expect($first['sequential_number'])->toBe(1)
        ->and($second['sequential_number'])->toBe(2)
        ->and($first['total_gross'])->toBe('29.60')
        ->and($first['gateway'])->toBe('local')
        ->and($first['gateway_status'])->toBe('simulated');

    actingAsMember(complianceTenant('Beta'));
    expect(issueDocument()['sequential_number'])->toBe(1);
});

test('the Agenzia delle Entrate driver refuses to run and nothing is issued', function (): void {
    Config::set('services.corrispettivi.driver', 'ade');

    $this->postJson('/api/v1/fiscal-retail/commercial-documents', ['lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 1, 'tax_rate' => 22]]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('gateway');

    expect(CommercialDocument::count())->toBe(0);
});

test('a POS sale gets one commercial document, also when sent to the fiscal printer', function (): void {
    $terminal = $this->postJson('/api/v1/retail/pos/terminals', ['terminal_code' => 'T-01', 'name' => 'Banco'])->assertCreated()->json('data.id');
    $session = $this->postJson('/api/v1/retail/pos/sessions', ['pos_terminal_id' => $terminal, 'opening_float' => 0])->assertCreated()->json('data.id');
    $sale = $this->postJson('/api/v1/retail/pos/sales', [
        'pos_session_id' => $session, 'payment_method' => 'card',
        'items' => [['description' => 'Pane', 'quantity' => 2, 'unit_price' => 10, 'tax_rate' => 22]],
    ])->assertCreated()->json('data');

    $this->postJson("/api/v1/retail/pos/sales/{$sale['id']}/rt-transmit")->assertSuccessful();
    $this->postJson('/api/v1/fiscal-retail/commercial-documents', ['pos_sale_id' => $sale['id']])->assertCreated();

    $document = CommercialDocument::where('pos_sale_id', $sale['id'])->sole();

    expect((string) $document->total_gross)->toBe((string) $sale['total_gross'])
        ->and($document->gateway_status)->toBe('simulated');
});

test('a POS sale of another tenant is refused', function (): void {
    actingAsMember(complianceTenant('Beta'));

    $this->postJson('/api/v1/fiscal-retail/commercial-documents', ['pos_sale_id' => '00000000-0000-0000-0000-000000000000'])->assertUnprocessable()->assertJsonValidationErrors('pos_sale_id');
});

test('a void reverses the whole document and cannot be repeated', function (): void {
    $document = issueDocument();

    $void = $this->postJson("/api/v1/fiscal-retail/commercial-documents/{$document['id']}/void")->assertCreated()->json('data');

    expect($void['document_type'])->toBe('void')
        ->and($void['related_document_id'])->toBe($document['id'])
        ->and($void['sequential_number'])->toBe(2);

    $this->postJson("/api/v1/fiscal-retail/commercial-documents/{$document['id']}/void")->assertUnprocessable();
    $this->postJson("/api/v1/fiscal-retail/commercial-documents/{$void['id']}/void")->assertUnprocessable();
    $this->postJson("/api/v1/fiscal-retail/commercial-documents/{$document['id']}/return")->assertUnprocessable();
});

test('partial returns stop at the quantity sold', function (): void {
    $document = issueDocument();
    $line = $this->getJson("/api/v1/fiscal-retail/commercial-documents/{$document['id']}")->json('data.lines.0');
    $return = fn (float $quantity) => $this->postJson("/api/v1/fiscal-retail/commercial-documents/{$document['id']}/return", ['lines' => [['line_id' => $line['id'], 'quantity' => $quantity]]]);

    expect($return(1)->assertCreated()->json('data.total_gross'))->toBe('12.20');
    $return(2)->assertUnprocessable();
    $return(1)->assertCreated();
    $return(1)->assertUnprocessable();

    $this->postJson("/api/v1/fiscal-retail/commercial-documents/{$document['id']}/return", ['lines' => [['line_id' => $document['id'], 'quantity' => 1]]])->assertUnprocessable();
});

test('the daily aggregate splits by VAT rate and subtracts voids and returns', function (): void {
    $kept = issueDocument();
    $voided = issueDocument();
    $this->postJson("/api/v1/fiscal-retail/commercial-documents/{$voided['id']}/void")->assertCreated();
    $line = $this->getJson("/api/v1/fiscal-retail/commercial-documents/{$kept['id']}")->json('data.lines.1');
    $this->postJson("/api/v1/fiscal-retail/commercial-documents/{$kept['id']}/return", ['lines' => [['line_id' => $line['id'], 'quantity' => 1]]])->assertCreated();

    $rows = collect($this->getJson('/api/v1/fiscal-retail/corrispettivi/daily/2027-04-15')->assertOk()->json('data.rows'))->keyBy('tax_rate');

    expect($rows['22.00']['sales_gross'])->toBe('48.80')
        ->and($rows['22.00']['voided_gross'])->toBe('24.40')
        ->and($rows['22.00']['total_net'])->toBe('20.00')
        ->and($rows['22.00']['total_tax'])->toBe('4.40')
        ->and($rows['4.00']['returned_gross'])->toBe('5.20')
        ->and($rows['4.00']['total_gross'])->toBe('0.00');
});

test('closing a day freezes it once and blocks new documents', function (): void {
    issueDocument();

    $this->postJson('/api/v1/fiscal-retail/corrispettivi/daily/2027-04-15/close')->assertCreated();
    $this->postJson('/api/v1/fiscal-retail/corrispettivi/daily/2027-04-15/close')->assertUnprocessable();
    $this->postJson('/api/v1/fiscal-retail/commercial-documents', ['lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 1, 'tax_rate' => 22]]])->assertUnprocessable();

    expect($this->getJson('/api/v1/fiscal-retail/corrispettivi/daily/2027-04-15')->json('data.closed'))->toBeTrue();

    $this->postJson('/api/v1/fiscal-retail/corrispettivi/daily/2027-04-16/close')->assertUnprocessable();
    $this->postJson('/api/v1/fiscal-retail/corrispettivi/daily/2027-04-14/close')->assertUnprocessable();
});

test('the register totals a month', function (): void {
    issueDocument();
    $this->travelTo('2027-04-16 10:00:00');
    issueDocument();
    $this->travelTo('2027-05-01 10:00:00');
    issueDocument();

    $register = $this->getJson('/api/v1/fiscal-retail/corrispettivi/register?year=2027&month=4')->assertOk()->json('data');

    expect($register['rows'])->toHaveCount(4)->and($register['totals']['total_gross'])->toBe('59.20');
});

test('documents and registers are isolated between tenants', function (): void {
    $document = issueDocument();

    actingAsMember(complianceTenant('Beta'));

    $this->getJson("/api/v1/fiscal-retail/commercial-documents/{$document['id']}")->assertNotFound();
    $this->postJson("/api/v1/fiscal-retail/commercial-documents/{$document['id']}/void")->assertNotFound();
    expect($this->getJson('/api/v1/fiscal-retail/commercial-documents')->json('data'))->toBe([])
        ->and($this->getJson('/api/v1/fiscal-retail/corrispettivi/daily/2027-04-15')->json('data.rows'))->toBe([]);
});

test('roles are enforced on the module', function (): void {
    actingAsMember($this->tenant, 'member');
    $this->getJson('/api/v1/fiscal-retail/commercial-documents')->assertForbidden();

    actingAsMember($this->tenant, 'accountant_readonly');
    $this->getJson('/api/v1/fiscal-retail/commercial-documents')->assertOk();
    $this->postJson('/api/v1/fiscal-retail/commercial-documents', [])->assertForbidden();

    actingAsMember($this->tenant, 'sales');
    $this->postJson('/api/v1/fiscal-retail/commercial-documents', [])->assertUnprocessable();
});

/**
 * Whether another database session could take the day lock now. The test transaction is still open, so a lock taken by
 * the request is still held.
 */
function corrispettiviDayIsFree(string $tenantId, string $day): bool
{
    Config::set('database.connections.lock_probe', config('database.connections.'.config('database.default')));

    try {
        return DB::connection('lock_probe')->transaction(static fn (): bool => (bool) DB::connection('lock_probe')
            ->selectOne('SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0)) AS taken', ["corrispettivi:{$tenantId}:{$day}"])->taken);
    } finally {
        DB::purge('lock_probe');
    }
}

test('issuing a document takes the lock of its day, the one the closure takes, so it cannot commit after the aggregation', function (): void {
    expect(corrispettiviDayIsFree($this->tenant->id, '2027-04-15'))->toBeTrue();

    issueDocument();

    expect(corrispettiviDayIsFree($this->tenant->id, '2027-04-15'))->toBeFalse()
        ->and(corrispettiviDayIsFree($this->tenant->id, '2027-04-14'))->toBeTrue();
});

test('closing a day takes the lock of that day', function (): void {
    $this->travelTo('2027-04-14 10:00:00');
    issueDocument();
    $this->travelTo('2027-04-15 10:00:00');
    $keys = [];
    DB::listen(function (QueryExecuted $query) use (&$keys): void {
        if (str_contains($query->sql, 'pg_advisory_xact_lock')) {
            $keys[] = $query->bindings[0];
        }
    });

    $this->postJson('/api/v1/fiscal-retail/corrispettivi/daily/2027-04-14/close')->assertCreated();

    expect($keys)->toBe(["corrispettivi:{$this->tenant->id}:2027-04-14"]);
});
