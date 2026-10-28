<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Treasury\Contracts\ForeignExchangeRiskServiceInterface;
use Modules\Treasury\Contracts\LiquidityManagementServiceInterface;
use Modules\Treasury\Contracts\TreasuryPositionServiceInterface;
use Modules\Treasury\Events\FXAdjustmentCalculatedEvent;
use Modules\Treasury\Events\TreasuryAllocationExecutedEvent;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\CashPool;
use Modules\Treasury\Models\IntercompanyFunding;
use Modules\Treasury\Models\WorkingCapitalMetric;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::first() ?? Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'Treasury Liquidity Tenant',
        'legal_name' => 'Treasury Liquidity SpA',
    ]);
    app(TenantContext::class)->setTenant($this->tenant);
    $this->tenantId = (string) $this->tenant->id;
    $this->liquidityService = app(LiquidityManagementServiceInterface::class);
    $this->fxService = app(ForeignExchangeRiskServiceInterface::class);
    $this->positionService = app(TreasuryPositionServiceInterface::class);

    // Create Header Account
    $this->headerAccount = BankAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'company_id' => $this->tenantId,
        'bank_name' => 'Intesa Central Treasury',
        'branch_name' => 'Corporate Milan',
        'account_number' => 'ACC-ZBA-HEADER',
        'account_type' => 'TREASURY',
        'iban' => 'IT00H0306901600000000000001',
        'currency' => 'EUR',
        'opening_balance' => 1000000.00,
        'current_balance' => 1000000.00,
        'available_balance' => 1000000.00,
        'is_pool_header' => true,
        'is_active' => true,
    ]);

    // Create Subsidiary Account
    $this->subAccount = BankAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'company_id' => $this->tenantId,
        'bank_name' => 'Intesa Rome Branch',
        'branch_name' => 'Commercial Rome',
        'account_number' => 'ACC-ZBA-SUB',
        'account_type' => 'OPERATING',
        'iban' => 'IT00S0306901600000000000002',
        'currency' => 'EUR',
        'opening_balance' => 80000.00,
        'current_balance' => 80000.00, // exceeds target 10,000 floor -> 70,000 sweep
        'available_balance' => 80000.00,
        'is_pool_header' => false,
        'is_active' => true,
    ]);

    // Create Cash Pool
    $this->cashPool = CashPool::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'pool_name' => 'Test ZBA Cash Pool',
        'pool_structure' => 'ZERO_BALANCE',
        'header_bank_account_id' => $this->headerAccount->id,
        'currency' => 'EUR',
        'sweeping_frequency' => 'DAILY',
        'target_balance_floor' => 10000.00,
        'target_balance_ceiling' => 500000.00,
        'is_active' => true,
    ]);
});

test('can execute Zero Balance Account (ZBA) sweeping and sweep surplus funds to header', function () {
    Event::fake([TreasuryAllocationExecutedEvent::class]);

    $userId = (string) Str::uuid7();

    $transfers = $this->liquidityService->executeCashConcentration($this->cashPool->id, $userId);

    expect(count($transfers))->toBeGreaterThanOrEqual(1);

    $this->subAccount->refresh();
    expect((float) $this->subAccount->current_balance)->toBe(10000.00);

    Event::assertDispatched(TreasuryAllocationExecutedEvent::class);
});

test('can calculate working capital metrics DSO, DPO, DIO, and CCC', function () {
    $metric = $this->liquidityService->calculateWorkingCapital(
        $this->tenantId,
        now()->toDateString()
    );

    expect($metric)->toBeInstanceOf(WorkingCapitalMetric::class)
        ->and($metric->cash_conversion_cycle_days)->not->toBeNull();
});

test('can compute currency exposure matrix and execute IAS 21 FX revaluation', function () {
    Event::fake([FXAdjustmentCalculatedEvent::class]);

    // Create USD Account with balance
    $usdAccount = BankAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'company_id' => $this->tenantId,
        'bank_name' => 'BNP Paribas USD',
        'branch_name' => 'New York Branch',
        'account_number' => 'ACC-USD-01',
        'account_type' => 'OPERATING',
        'iban' => 'US11X010050000000000000001',
        'currency' => 'USD',
        'opening_balance' => 100000.00,
        'current_balance' => 100000.00,
        'available_balance' => 100000.00,
        'is_active' => true,
    ]);

    // 1. Exposure Matrix
    $exposure = $this->fxService->computeCurrencyExposure($this->tenantId, now()->toDateString());
    expect($exposure)->toBeArray()
        ->and(count($exposure))->toBeGreaterThanOrEqual(1);

    // 2. IAS 21 Revaluation
    $reval = $this->fxService->performFxRevaluation(
        $this->tenantId,
        'USD',
        now()->toDateString(),
        1.0500,
        null,
        (string) Str::uuid7()
    );

    expect($reval->currency)->toBe('USD')
        ->and((float) $reval->spot_rate)->toBe(1.0500)
        ->and($reval->gain_or_loss)->toBeIn(['GAIN', 'LOSS', 'NEUTRAL']);

    Event::assertDispatched(FXAdjustmentCalculatedEvent::class);
});

test('can execute intercompany funding transfer between bank accounts', function () {
    $userId = (string) Str::uuid7();

    $transfer = $this->liquidityService->transferIntercompanyFunds(
        sourceBankAccountId: $this->headerAccount->id,
        destinationBankAccountId: $this->subAccount->id,
        amount: 50000.00,
        transferDate: now()->toDateString(),
        interestRate: 0.0350,
        cashPoolId: $this->cashPool->id,
        userId: $userId
    );

    expect($transfer)->toBeInstanceOf(IntercompanyFunding::class)
        ->and((float) $transfer->transfer_amount)->toBe(50000.00)
        ->and((float) $transfer->interest_rate)->toBe(0.0350);

    $this->headerAccount->refresh();
    $this->subAccount->refresh();

    expect((float) $this->headerAccount->current_balance)->toBe(950000.00)
        ->and((float) $this->subAccount->current_balance)->toBe(130000.00);
});
