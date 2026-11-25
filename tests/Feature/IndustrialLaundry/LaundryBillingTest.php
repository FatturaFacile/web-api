<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\IndustrialLaundry\Models\BillingRun;
use Modules\IndustrialLaundry\Models\Garment;
use Modules\IndustrialLaundry\Models\GarmentEvent;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Models\SalesInvoice;

require_once __DIR__.'/IndustrialLaundrySupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ilrSetUp($this);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ilrContractPayload(object $test, array $overrides = []): array
{
    return array_merge([
        'code' => 'ILR-HOTEL', 'customer_id' => (string) $test->customer->id, 'customer_segment' => 'hotel', 'valid_from' => '2028-01-01', 'valid_to' => '2028-12-31',
        'billing_model' => 'per_piece_washed', 'unreturned_threshold_days' => 60, 'jurisdiction' => 'IT', 'currency' => 'EUR',
        'lines' => [['article_type' => 'flat_linen', 'circulating_stock_qty' => 10, 'weekly_min_billing_percentage' => '50', 'unit_wash_price' => '1.00', 'replacement_price' => '20']],
    ], $overrides);
}

/**
 * February 2028 for the hotel: 12 garments delivered clean on Monday the 7th, and one garment that has been at the hotel
 * since the middle of December (past the 60 days threshold at the end of the month).
 */
function ilrFebruaryActivity(object $test): Garment
{
    foreach (range(1, 12) as $number) {
        $garment = ilrGarment($test, $number, ['last_delivered_at' => '2028-02-07 10:00:00']);
        GarmentEvent::query()->create([
            'tenant_id' => $test->tenant->id, 'garment_id' => $garment->id, 'contact_id' => $test->customer->id, 'article_type' => 'flat_linen',
            'event_type' => 'delivered_clean', 'wash_cycles_count' => 1, 'occurred_at' => '2028-02-07 10:00:00',
        ]);
    }

    return ilrGarment($test, 99, ['last_delivered_at' => '2027-12-15 08:00:00']);
}

/**
 * @return array<string, string>
 */
function ilrPeriod(object $test, string $contractId): array
{
    return ['contract_id' => $contractId, 'period_start' => '2028-02-01', 'period_end' => '2028-02-29'];
}

it('creates a par stock contract with its lines and the default tax rate of the jurisdiction', function (): void {
    $response = $this->postJson('/api/v1/industrial-laundry/par-contracts', ilrContractPayload($this))->assertCreated();

    expect((float) $response->json('data.tax_rate'))->toBe(22.0)
        ->and($response->json('data.lines'))->toHaveCount(1)
        ->and($response->json('data.lines.0.article_type'))->toBe('flat_linen')
        ->and($response->json('data.billing_model'))->toBe('per_piece_washed')
        ->and($response->json('data.unreturned_threshold_days'))->toBe(60);

    $swiss = $this->postJson('/api/v1/industrial-laundry/par-contracts', ilrContractPayload($this, ['code' => 'ILR-CH', 'customer_id' => (string) $this->otherCustomer->id, 'jurisdiction' => 'CH', 'currency' => 'chf']))->assertCreated();

    expect((float) $swiss->json('data.tax_rate'))->toBe(8.1)
        ->and($swiss->json('data.currency'))->toBe('CHF');

    $this->getJson('/api/v1/industrial-laundry/par-contracts')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/industrial-laundry/par-contracts?segment=clinic')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/industrial-laundry/par-contracts/'.$response->json('data.id'))->assertOk()->assertJsonPath('data.code', 'ILR-HOTEL');
});

it('refuses an invalid par stock contract', function (): void {
    $this->postJson('/api/v1/industrial-laundry/par-contracts', ilrContractPayload($this))->assertCreated();

    $this->postJson('/api/v1/industrial-laundry/par-contracts', ilrContractPayload($this, ['code' => 'ILR-HOTEL', 'valid_from' => '2029-01-01', 'valid_to' => null]))->assertUnprocessable();
    $this->postJson('/api/v1/industrial-laundry/par-contracts', ilrContractPayload($this, ['code' => 'OTHER', 'valid_from' => '2029-01-01', 'valid_to' => '2028-01-01']))->assertUnprocessable();
    $this->postJson('/api/v1/industrial-laundry/par-contracts', ilrContractPayload($this, ['code' => 'OTHER', 'billing_model' => 'by_the_hour', 'valid_from' => '2029-01-01', 'valid_to' => null]))->assertUnprocessable();
    $this->postJson('/api/v1/industrial-laundry/par-contracts', ilrContractPayload($this, ['code' => 'OTHER', 'valid_from' => '2029-01-01', 'valid_to' => null, 'lines' => [
        ['article_type' => 'flat_linen', 'circulating_stock_qty' => 1], ['article_type' => 'flat_linen', 'circulating_stock_qty' => 2],
    ]]))->assertUnprocessable();
    $this->postJson('/api/v1/industrial-laundry/par-contracts', ilrContractPayload($this, ['code' => 'OTHER', 'valid_from' => '2029-01-01', 'valid_to' => null, 'lines' => [
        ['article_type' => 'flat_linen', 'circulating_stock_qty' => 1, 'season_start_month' => 6, 'season_end_month' => 9],
    ]]))->assertUnprocessable()->assertJsonPath('code', 'season_incomplete');
});

it('refuses a second active contract of the customer in the same period', function (): void {
    $this->postJson('/api/v1/industrial-laundry/par-contracts', ilrContractPayload($this))->assertCreated();

    $this->postJson('/api/v1/industrial-laundry/par-contracts', ilrContractPayload($this, ['code' => 'ILR-CLASH', 'valid_from' => '2028-06-01', 'valid_to' => '2029-06-01']))
        ->assertUnprocessable()->assertJsonPath('code', 'contract_overlap');
    $this->postJson('/api/v1/industrial-laundry/par-contracts', ilrContractPayload($this, ['code' => 'ILR-NEXT', 'valid_from' => '2029-01-01', 'valid_to' => null]))->assertCreated();
});

it('updates a contract and replaces its lines', function (): void {
    $id = (string) $this->postJson('/api/v1/industrial-laundry/par-contracts', ilrContractPayload($this))->assertCreated()->json('data.id');

    $response = $this->putJson("/api/v1/industrial-laundry/par-contracts/{$id}", [
        'billing_model' => 'hybrid_rental_plus_wash',
        'lines' => [
            ['article_type' => 'flat_linen', 'circulating_stock_qty' => 20, 'unit_wash_price' => '0.50', 'rental_price' => '0.80'],
            ['article_type' => 'terry_towel', 'circulating_stock_qty' => 5, 'unit_wash_price' => '0.40'],
        ],
    ])->assertOk();

    expect($response->json('data.billing_model'))->toBe('hybrid_rental_plus_wash')
        ->and($response->json('data.lines'))->toHaveCount(2)
        ->and($response->json('data.lines.0.circulating_stock_qty'))->toBe(20);
});

it('previews the monthly bill without storing anything', function (): void {
    $contractId = (string) $this->postJson('/api/v1/industrial-laundry/par-contracts', ilrContractPayload($this))->assertCreated()->json('data.id');
    ilrFebruaryActivity($this);

    $response = $this->postJson('/api/v1/industrial-laundry/billing-previews', ilrPeriod($this, $contractId))->assertOk();

    expect(collect($response->json('data.lines'))->map(fn (array $line): string => $line['kind'].':'.(float) $line['quantity'].':'.(float) $line['amount'])->all())
        ->toBe(['wash_piece:12:12', 'minimum_top_up:17:17', 'unreturned:1:20'])
        ->and($response->json('data.washed_pieces'))->toBe(12)
        ->and((float) $response->json('data.subtotal'))->toBe(49.0)
        ->and((float) $response->json('data.tax_amount'))->toBe(10.78)
        ->and((float) $response->json('data.total'))->toBe(59.78)
        ->and($response->json('data.document_type'))->toBe('TD24')
        ->and($response->json('data.compliance.document'))->toBe('TD24')
        ->and(BillingRun::query()->count())->toBe(0);
});

it('consolidates the bill into a run without an invoice and refuses to bill the period twice', function (): void {
    $contractId = (string) $this->postJson('/api/v1/industrial-laundry/par-contracts', ilrContractPayload($this))->assertCreated()->json('data.id');
    $unreturned = ilrFebruaryActivity($this);

    $response = $this->postJson('/api/v1/industrial-laundry/billing-runs', ilrPeriod($this, $contractId) + ['create_invoice' => false])->assertCreated();

    expect($response->json('data.status'))->toBe('drafted')
        ->and($response->json('data.invoice_id'))->toBeNull()
        ->and($response->json('data.lines'))->toHaveCount(3)
        ->and((float) $response->json('data.wash_amount'))->toBe(12.0)
        ->and((float) $response->json('data.minimum_top_up_amount'))->toBe(17.0)
        ->and((float) $response->json('data.unreturned_amount'))->toBe(20.0)
        ->and((float) $response->json('data.total'))->toBe(59.78)
        ->and($response->json('data.compliance_report.returnable_containers'))->toBe(['out' => 0, 'back' => 0, 'at_customer' => 0])
        ->and($unreturned->refresh()->current_lifecycle_state->value)->toBe('delivered_to_customer');

    $this->postJson('/api/v1/industrial-laundry/billing-runs', ilrPeriod($this, $contractId) + ['create_invoice' => false])
        ->assertUnprocessable()->assertJsonPath('code', 'billing_period_already_billed');
    $this->postJson('/api/v1/industrial-laundry/billing-runs', ['contract_id' => $contractId, 'period_start' => '2028-02-15', 'period_end' => '2028-03-15', 'create_invoice' => false])
        ->assertUnprocessable()->assertJsonPath('code', 'billing_period_already_billed');

    $this->getJson('/api/v1/industrial-laundry/billing-runs')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/industrial-laundry/billing-runs/'.$response->json('data.id'))->assertOk()->assertJsonCount(3, 'data.lines');
});

it('drafts the TD24 invoice of a run and writes the unreturned garments off as rags, once', function (): void {
    $contractId = (string) $this->postJson('/api/v1/industrial-laundry/par-contracts', ilrContractPayload($this))->assertCreated()->json('data.id');
    $unreturned = ilrFebruaryActivity($this);
    $runId = (string) $this->postJson('/api/v1/industrial-laundry/billing-runs', ilrPeriod($this, $contractId) + ['create_invoice' => false])->assertCreated()->json('data.id');

    $this->mock(InvoicingServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('createInvoice')->once()->withArgs(fn (array $data, array $items): bool => $data['document_type'] === 'TD24'
            && $data['jurisdiction'] === 'IT'
            && $data['date'] === '2028-02-29'
            && $data['invoice_type'] === 'standard'
            && count($items) === 3
            && $items[0]['unit_price'] === '1.0000'
            && $items[0]['tax_rate'] === '22.0000')
            ->andReturn(new SalesInvoice(['id' => (string) Str::uuid7(), 'invoice_number' => 'FT-TEST-1']));
    });

    $response = $this->postJson("/api/v1/industrial-laundry/billing-runs/{$runId}/invoice")->assertOk();

    expect($response->json('data.status'))->toBe('invoiced')
        ->and($response->json('data.invoice_number'))->toBe('FT-TEST-1');

    $garment = $unreturned->refresh();

    expect($garment->current_lifecycle_state->value)->toBe('condemned_rag')
        ->and($garment->mending_or_condemn_reason)->toBe('unreturned_billed')
        ->and($garment->unreturned_billed_at)->not->toBeNull()
        ->and(GarmentEvent::query()->where('event_type', 'unreturned_billed')->count())->toBe(1);

    $this->postJson("/api/v1/industrial-laundry/billing-runs/{$runId}/invoice")->assertUnprocessable()->assertJsonPath('code', 'billing_run_already_invoiced');
});

it('drafts the invoice at once when the run is consolidated with one', function (): void {
    $contractId = (string) $this->postJson('/api/v1/industrial-laundry/par-contracts', ilrContractPayload($this))->assertCreated()->json('data.id');
    ilrFebruaryActivity($this);

    $this->mock(InvoicingServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('createInvoice')->once()->andReturn(new SalesInvoice(['id' => (string) Str::uuid7(), 'invoice_number' => 'FT-TEST-2']));
    });

    $this->postJson('/api/v1/industrial-laundry/billing-runs', ilrPeriod($this, $contractId))->assertCreated()
        ->assertJsonPath('data.status', 'invoiced')->assertJsonPath('data.invoice_number', 'FT-TEST-2');
});

it('refuses to bill a period with nothing in it, outside the contract or too long', function (): void {
    $contractId = (string) $this->postJson('/api/v1/industrial-laundry/par-contracts', ilrContractPayload($this, [
        'lines' => [['article_type' => 'flat_linen', 'circulating_stock_qty' => 10, 'unit_wash_price' => '1.00']],
    ]))->assertCreated()->json('data.id');

    $this->postJson('/api/v1/industrial-laundry/billing-runs', ['contract_id' => $contractId, 'period_start' => '2028-03-01', 'period_end' => '2028-03-31', 'create_invoice' => false])
        ->assertUnprocessable()->assertJsonPath('code', 'nothing_to_bill');
    $this->postJson('/api/v1/industrial-laundry/billing-runs', ['contract_id' => $contractId, 'period_start' => '2027-02-01', 'period_end' => '2027-02-28', 'create_invoice' => false])
        ->assertUnprocessable()->assertJsonPath('code', 'period_outside_contract');
    $this->postJson('/api/v1/industrial-laundry/billing-runs', ['contract_id' => $contractId, 'period_start' => '2028-01-01', 'period_end' => '2028-06-30', 'create_invoice' => false])
        ->assertUnprocessable()->assertJsonPath('code', 'period_invalid');
    $this->postJson('/api/v1/industrial-laundry/billing-runs', ['contract_id' => $contractId, 'period_start' => '2028-03-31', 'period_end' => '2028-03-01'])->assertUnprocessable();

    expect(BillingRun::query()->count())->toBe(0);
});

it('bills the rental of the workwear in circulation, not of the condemned garments', function (): void {
    $contractId = (string) $this->postJson('/api/v1/industrial-laundry/par-contracts', ilrContractPayload($this, [
        'code' => 'ILR-WORK', 'billing_model' => 'fixed_monthly_rental_per_garment',
        'lines' => [['article_type' => 'personal_workwear_uniform', 'circulating_stock_qty' => 3, 'rental_price' => '4.50', 'replacement_price' => '45']],
    ]))->assertCreated()->json('data.id');

    foreach ([1, 2, 3] as $number) {
        ilrGarment($this, $number, ['article_type' => 'personal_workwear_uniform', 'last_delivered_at' => '2028-02-20 08:00:00']);
    }

    ilrGarment($this, 4, ['article_type' => 'personal_workwear_uniform', 'current_lifecycle_state' => 'condemned_rag']);

    $response = $this->postJson('/api/v1/industrial-laundry/billing-previews', ilrPeriod($this, $contractId))->assertOk();

    expect((float) $response->json('data.rental_amount'))->toBe(13.5)
        ->and($response->json('data.lines'))->toHaveCount(1)
        ->and($response->json('data.lines.0.kind'))->toBe('rental')
        ->and((float) $response->json('data.lines.0.quantity'))->toBe(3.0);
});

it('keeps the monthly bill of the finance roles: a sales manager previews but cannot consolidate', function (): void {
    $contractId = (string) $this->postJson('/api/v1/industrial-laundry/par-contracts', ilrContractPayload($this))->assertCreated()->json('data.id');
    ilrFebruaryActivity($this);

    treasuryActor($this, 'sales_manager');

    $this->postJson('/api/v1/industrial-laundry/billing-previews', ilrPeriod($this, $contractId))->assertOk();
    $this->postJson('/api/v1/industrial-laundry/billing-runs', ilrPeriod($this, $contractId) + ['create_invoice' => false])->assertForbidden();
    $this->getJson('/api/v1/industrial-laundry/billing-runs')->assertOk();
});

it('keeps the contracts and runs of a company away from the others', function (): void {
    $contractId = (string) $this->postJson('/api/v1/industrial-laundry/par-contracts', ilrContractPayload($this))->assertCreated()->json('data.id');

    $other = Tenant::create(['name' => 'Lavanderia Beta', 'legal_name' => 'Lavanderia Beta Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    treasuryActor($this, 'accountant', $other);

    $this->getJson("/api/v1/industrial-laundry/par-contracts/{$contractId}")->assertNotFound();
    $this->getJson('/api/v1/industrial-laundry/par-contracts')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson('/api/v1/industrial-laundry/billing-previews', ilrPeriod($this, $contractId))->assertNotFound();
});
