<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Treasury\Contracts\CashForecastServiceInterface;
use Modules\Treasury\Events\CashForecastGeneratedEvent;
use Modules\Treasury\Events\LiquidityAlertEvent;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\CashForecast;
use Modules\Treasury\Models\ForecastScenario;
use Modules\Treasury\Models\PaymentBatch;
use Modules\Treasury\Models\ReceivableCollection;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::first() ?? Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'Treasury Forecast Tenant',
        'legal_name' => 'Treasury Forecast SpA',
    ]);
    app(TenantContext::class)->setTenant($this->tenant);
    $this->tenantId = (string) $this->tenant->id;
    $this->forecastService = app(CashForecastServiceInterface::class);

    // Seed Baseline Scenario
    $this->scenario = ForecastScenario::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'company_id' => $this->tenantId,
        'code' => 'BASELINE-TEST',
        'name' => 'Baseline Test Scenario',
        'is_baseline' => true,
        'collection_delay_days' => 0,
        'disbursement_acceleration_days' => 0,
        'sales_growth_factor' => 1.0,
        'is_active' => true,
    ]);

    // Seed Bank Account
    $this->bankAccount = BankAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'company_id' => $this->tenantId,
        'bank_name' => 'Intesa Sanpaolo S.p.A.',
        'branch_name' => 'Corporate Sede',
        'account_number' => 'ACC-FORECAST-01',
        'account_type' => 'OPERATING',
        'iban' => 'IT11A0306901600000000012345',
        'bic_swift' => 'BCITITMM',
        'currency' => 'EUR',
        'opening_balance' => 100000.00,
        'current_balance' => 100000.00,
        'available_balance' => 100000.00,
        'is_active' => true,
    ]);
});

test('can generate 90-day cash forecast incorporating expected receivables and scheduled payments', function () {
    Event::fake([CashForecastGeneratedEvent::class]);

    // Create expected inbound receivable
    ReceivableCollection::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'company_id' => $this->tenantId,
        'customer_id' => (string) Str::uuid7(),
        'invoice_id' => (string) Str::uuid7(),
        'expected_amount' => 50000.00,
        'collected_amount' => 0.00,
        'due_date' => now()->addDays(20)->toDateString(),
        'payment_method' => 'WIRE_INBOUND',
        'status' => 'PENDING',
        'aging_bucket' => 'CURRENT',
        'dunning_level' => 0,
    ]);

    // Create scheduled outbound payment batch
    PaymentBatch::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'company_id' => $this->tenantId,
        'bank_account_id' => $this->bankAccount->id,
        'batch_reference' => 'BATCH-FC-001',
        'payment_method' => 'SEPA_CREDIT_TRANSFER',
        'execution_date' => now()->addDays(15)->toDateString(),
        'currency' => 'EUR',
        'total_amount' => 30000.00,
        'item_count' => 1,
        'status' => 'APPROVED',
    ]);

    $forecast = $this->forecastService->generateForecast(
        $this->tenantId,
        'BASELINE-TEST',
        90,
        now()->toDateString()
    );

    $expectedClosing = (float) $forecast->opening_liquidity + 20000.00;

    expect($forecast)->toBeInstanceOf(CashForecast::class)
        ->and((float) $forecast->opening_liquidity)->toBeGreaterThanOrEqual(100000.00)
        ->and((float) $forecast->total_inflows)->toBeGreaterThanOrEqual(50000.00)
        ->and((float) $forecast->total_outflows)->toBeGreaterThanOrEqual(30000.00)
        ->and((float) $forecast->closing_liquidity)->not->toBeNull()
        ->and($forecast->has_liquidity_deficit)->toBeFalse();

    Event::assertDispatched(CashForecastGeneratedEvent::class);
});

test('can detect liquidity deficit below threshold and trigger LiquidityAlertEvent', function () {
    Event::fake([CashForecastGeneratedEvent::class, LiquidityAlertEvent::class]);

    $openingCash = (float) BankAccount::where('tenant_id', $this->tenantId)->where('is_active', true)->sum('current_balance');
    $largeOutflow = $openingCash + 100000.00;

    // Outflow exceeds opening balance + inflows
    PaymentBatch::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'company_id' => $this->tenantId,
        'bank_account_id' => $this->bankAccount->id,
        'batch_reference' => 'BATCH-LARGE-OUTFLOW',
        'payment_method' => 'SEPA_CREDIT_TRANSFER',
        'execution_date' => now()->addDays(10)->toDateString(),
        'currency' => 'EUR',
        'total_amount' => $largeOutflow,
        'item_count' => 1,
        'status' => 'approved',
    ]);

    $forecast = $this->forecastService->generateForecast(
        $this->tenantId,
        'BASELINE-TEST',
        30,
        now()->toDateString()
    );

    expect($forecast->has_liquidity_deficit)->toBeTrue()
        ->and((float) $forecast->closing_liquidity)->toBeLessThan(0.00)
        ->and((float) $forecast->deficit_amount)->toBeGreaterThan(0.00);

    Event::assertDispatched(LiquidityAlertEvent::class);
});
