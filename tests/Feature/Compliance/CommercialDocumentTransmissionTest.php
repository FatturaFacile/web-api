<?php

declare(strict_types=1);

use App\Contracts\FiscalPrinterGateway;
use App\Models\PosSale;
use App\Models\RtFiscalReceipt;
use App\Models\Tenant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\FiscalRetail\Actions\IssueCommercialDocument;
use Modules\FiscalRetail\Contracts\CorrispettiviGateway;
use Modules\FiscalRetail\Exceptions\CorrispettiviGatewayException;
use Modules\FiscalRetail\Gateways\LocalCorrispettiviGateway;
use Modules\FiscalRetail\Jobs\RetryCorrispettiviTransmissionsJob;
use Modules\FiscalRetail\Models\CommercialDocument;
use Modules\FiscalRetail\Models\DailyCorrispettivo;

require_once __DIR__.'/ComplianceHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2027-04-15 10:00:00');
    $this->tenant = complianceTenant();
    actingAsMember($this->tenant);
});

/**
 * A POS sale of the acting tenant, through the API.
 *
 * @return array<string, mixed>
 */
function transmissionPosSale(): array
{
    $terminal = test()->postJson('/api/v1/retail/pos/terminals', ['terminal_code' => 'T-'.random_int(1, 99999), 'name' => 'Banco'])->assertCreated()->json('data.id');
    $session = test()->postJson('/api/v1/retail/pos/sessions', ['pos_terminal_id' => $terminal, 'opening_float' => 0])->assertCreated()->json('data.id');

    return test()->postJson('/api/v1/retail/pos/sales', [
        'pos_session_id' => $session, 'payment_method' => 'cash',
        'items' => [['description' => 'Pane', 'quantity' => 2, 'unit_price' => 10, 'tax_rate' => 22]],
    ])->assertCreated()->json('data');
}

/**
 * A gateway that fails the first `$failures` calls and records every call: the idempotency key and whether an
 * application transaction was open (the test transaction is level 1).
 */
function flakyCorrispettiviGateway(int $failures): object
{
    $gateway = new class($failures) implements CorrispettiviGateway
    {
        /** @var list<array{kind: string, key: string, transaction_level: int}> */
        public array $calls = [];

        public function __construct(public int $failures) {}

        public function name(): string
        {
            return 'local';
        }

        public function assertAvailable(): void {}

        public function sendDocument(Tenant $tenant, CommercialDocument $document, string $idempotencyKey): array
        {
            return $this->answer('document', $idempotencyKey);
        }

        public function sendDailyTotals(Tenant $tenant, string $businessDate, array $rows, string $idempotencyKey): array
        {
            return $this->answer('day', $idempotencyKey);
        }

        /**
         * @return array{status: string, reference: string}
         */
        private function answer(string $kind, string $key): array
        {
            $this->calls[] = ['kind' => $kind, 'key' => $key, 'transaction_level' => DB::transactionLevel()];

            if ($this->failures-- > 0) {
                throw new CorrispettiviGatewayException('Channel unreachable');
            }

            return ['status' => 'accepted', 'reference' => 'REF-'.$key];
        }
    };

    app()->instance(LocalCorrispettiviGateway::class, $gateway);

    return $gateway;
}

function retryTransmissions(Tenant $tenant): void
{
    app()->call([new RetryCorrispettiviTransmissionsJob($tenant->id), 'handle']);
}

test('a POS sale is issued once: the sale row is locked and the database refuses a second sale document', function (): void {
    $sale = PosSale::findOrFail(transmissionPosSale()['id']);
    $locks = [];
    DB::listen(function (QueryExecuted $query) use (&$locks): void {
        if (str_contains($query->sql, 'from "pos_sales"') && str_contains($query->sql, 'for update')) {
            $locks[] = $query->sql;
        }
    });

    $first = app(IssueCommercialDocument::class)->fromPosSale($sale);
    $second = app(IssueCommercialDocument::class)->fromPosSale($sale);

    expect($second->id)->toBe($first->id)
        ->and(CommercialDocument::where('pos_sale_id', $sale->id)->count())->toBe(1)
        ->and($locks)->not->toBeEmpty();

    expect(fn () => DB::transaction(fn () => CommercialDocument::create([
        'pos_sale_id' => $sale->id, 'document_type' => 'sale', 'fiscal_year' => 2027, 'sequential_number' => 99, 'document_number' => '99/2027',
        'issue_date' => '2027-04-15', 'total_net' => 0, 'total_tax' => 0, 'total_gross' => 0,
    ])))->toThrow(UniqueConstraintViolationException::class);
});

test('a refunded POS sale gets no commercial document', function (): void {
    $sale = transmissionPosSale();
    $this->postJson("/api/v1/retail/pos/sales/{$sale['id']}/refund")->assertOk();

    $this->postJson('/api/v1/fiscal-retail/commercial-documents', ['pos_sale_id' => $sale['id']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('pos_sale_id');

    expect(CommercialDocument::count())->toBe(0);
});

test('refunding a POS sale voids its commercial document, so the day no longer counts it', function (): void {
    $sale = transmissionPosSale();
    $document = $this->postJson('/api/v1/fiscal-retail/commercial-documents', ['pos_sale_id' => $sale['id']])->assertCreated()->json('data');

    $this->postJson("/api/v1/retail/pos/sales/{$sale['id']}/refund")->assertOk();

    $void = CommercialDocument::where('related_document_id', $document['id'])->sole();
    $rows = $this->getJson('/api/v1/fiscal-retail/corrispettivi/daily/2027-04-15')->assertOk()->json('data.rows');

    expect($void->document_type)->toBe('void')
        ->and($rows[0]['voided_gross'])->toBe($rows[0]['sales_gross'])
        ->and($rows[0]['total_gross'])->toBe('0.00');
});

test('a refund is refused when the commercial document of the sale cannot be voided', function (): void {
    $sale = transmissionPosSale();
    $document = $this->postJson('/api/v1/fiscal-retail/commercial-documents', ['pos_sale_id' => $sale['id']])->assertCreated()->json('data');
    $this->postJson("/api/v1/fiscal-retail/commercial-documents/{$document['id']}/return")->assertCreated();

    $this->postJson("/api/v1/retail/pos/sales/{$sale['id']}/refund")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sale');

    expect(PosSale::findOrFail($sale['id'])->status)->not->toBe('refunded')
        ->and(CommercialDocument::where('related_document_id', $document['id'])->where('document_type', 'void')->exists())->toBeFalse();
});

test('a document is sent after the commit, stays issued when the gateway fails and is retried with the same key', function (): void {
    $gateway = flakyCorrispettiviGateway(failures: 1);

    $document = $this->postJson('/api/v1/fiscal-retail/commercial-documents', [
        'lines' => [['description' => 'Pane', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 22]],
    ])->assertCreated()->json('data');

    expect($document['gateway_status'])->toBe('failed')
        ->and($document['sequential_number'])->toBe(1)
        ->and($gateway->calls[0]['transaction_level'])->toBe(1);

    retryTransmissions($this->tenant);
    retryTransmissions($this->tenant);

    $stored = CommercialDocument::findOrFail($document['id']);

    expect($stored->gateway_status)->toBe('accepted')
        ->and($stored->gateway_error)->toBeNull()
        ->and($gateway->calls)->toHaveCount(2)
        ->and($gateway->calls[1]['key'])->toBe($gateway->calls[0]['key'])
        ->and($gateway->calls[0]['key'])->toBe($stored->transmission_key);
});

test('a closed day is sent after the commit and stays closed when the gateway fails, then the retry sends it', function (): void {
    $this->postJson('/api/v1/fiscal-retail/commercial-documents', ['lines' => [['description' => 'Pane', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 22]]])->assertCreated();
    $gateway = flakyCorrispettiviGateway(failures: 1);

    $rows = $this->postJson('/api/v1/fiscal-retail/corrispettivi/daily/2027-04-15/close')->assertCreated()->json('data');

    expect($rows[0]['gateway_status'])->toBe('failed')
        ->and(collect($gateway->calls)->firstWhere('kind', 'day')['transaction_level'])->toBe(1);
    $this->postJson('/api/v1/fiscal-retail/corrispettivi/daily/2027-04-15/close')->assertUnprocessable();

    retryTransmissions($this->tenant);

    $dayCalls = collect($gateway->calls)->where('kind', 'day')->values();

    expect(DailyCorrispettivo::sole()->gateway_status)->toBe('accepted')
        ->and($dayCalls)->toHaveCount(2)
        ->and($dayCalls[1]['key'])->toBe($dayCalls[0]['key']);
});

test('a fiscal printer failure keeps the receipt for a retry instead of losing the sale', function (): void {
    $sale = transmissionPosSale();
    $printer = new class implements FiscalPrinterGateway
    {
        public int $calls = 0;

        public function transmit(PosSale $sale): array
        {
            if ($this->calls++ === 0) {
                throw new RuntimeException('Printer offline');
            }

            return ['fiscal_document_number' => 'RT-1', 'xml_lot_id' => 'LOT-1', 'z_report_number' => 'Z-1'];
        }

        public function isSimulated(): bool
        {
            return false;
        }
    };
    app()->instance(FiscalPrinterGateway::class, $printer);

    $failed = $this->postJson("/api/v1/retail/pos/sales/{$sale['id']}/rt-transmit")->assertCreated()->json('data');
    $sent = $this->postJson("/api/v1/retail/pos/sales/{$sale['id']}/rt-transmit")->assertCreated()->json('data');

    expect($failed['rt_status'])->toBe(RtFiscalReceipt::StatusError)
        ->and($sent['rt_status'])->toBe(RtFiscalReceipt::StatusTransmitted)
        ->and($sent['fiscal_document_number'])->toBe('RT-1')
        ->and(RtFiscalReceipt::count())->toBe(1)
        ->and(CommercialDocument::where('pos_sale_id', $sale['id'])->count())->toBe(1);

    $this->postJson("/api/v1/retail/pos/sales/{$sale['id']}/rt-transmit")->assertUnprocessable();
});
