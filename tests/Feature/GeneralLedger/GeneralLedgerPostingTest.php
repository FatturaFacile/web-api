<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\CustomerLedger;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\JournalEntry;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::first() ?? Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'GL Posting Test Tenant',
        'legal_name' => 'GL Posting SpA',
    ]);
    app(TenantContext::class)->setTenant($this->tenant);
    $this->tenantId = (string) $this->tenant->id;
    $this->postingService = app(DoubleEntryPostingServiceInterface::class);

    $this->fiscalYear = FiscalYear::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'year_code' => 'FY2026-TEST',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => 'open',
    ]);

    $this->period = FiscalPeriod::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'fiscal_year_id' => $this->fiscalYear->id,
        'period_number' => 1,
        'period_name' => 'January 2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => 'open',
    ]);

    $this->cashAccount = ChartOfAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'account_code' => '1000',
        'name' => 'Cash',
        'account_class' => 'asset',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
        'is_active' => true,
    ]);

    $this->revenueAccount = ChartOfAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'account_code' => '4000',
        'name' => 'Sales Revenue',
        'account_class' => 'revenue',
        'account_type' => 'revenue',
        'normal_balance' => 'credit',
        'is_active' => true,
    ]);
});

test('posting engine accepts balanced double-entry journals', function () {
    $entry = $this->postingService->createAndPost([
        'tenant_id' => $this->tenantId,
        'fiscal_period_id' => $this->period->id,
        'entry_number' => 'JE-BALANCED-001',
        'description' => 'Cash Sale',
        'lines' => [
            [
                'account_id' => $this->cashAccount->id,
                'debit_amount' => 500.00,
                'credit_amount' => 0.00,
                'description' => 'Debit Cash',
            ],
            [
                'account_id' => $this->revenueAccount->id,
                'debit_amount' => 0.00,
                'credit_amount' => 500.00,
                'description' => 'Credit Revenue',
            ],
        ],
    ]);

    expect($entry)->toBeInstanceOf(JournalEntry::class)
        ->and($entry->status)->toBe('posted')
        ->and((float) $entry->total_amount)->toBe(500.00)
        ->and($entry->isBalanced())->toBeTrue();
});

test('posting engine rejects unbalanced journal entries', function () {
    $this->postingService->createAndPost([
        'tenant_id' => $this->tenantId,
        'fiscal_period_id' => $this->period->id,
        'entry_number' => 'JE-UNBALANCED-001',
        'description' => 'Unbalanced Sale',
        'lines' => [
            [
                'account_id' => $this->cashAccount->id,
                'debit_amount' => 500.00,
                'credit_amount' => 0.00,
            ],
            [
                'account_id' => $this->revenueAccount->id,
                'debit_amount' => 0.00,
                'credit_amount' => 450.00, // Unbalanced by 50
            ],
        ],
    ]);
})->throws(InvalidArgumentException::class);

test('posting engine supports journal reversal with opposing lines and links', function () {
    $original = $this->postingService->createAndPost([
        'tenant_id' => $this->tenantId,
        'fiscal_period_id' => $this->period->id,
        'entry_number' => 'JE-TO-REVERSE-001',
        'description' => 'Original Entry',
        'lines' => [
            [
                'account_id' => $this->cashAccount->id,
                'debit_amount' => 200.00,
                'credit_amount' => 0.00,
            ],
            [
                'account_id' => $this->revenueAccount->id,
                'debit_amount' => 0.00,
                'credit_amount' => 200.00,
            ],
        ],
    ]);

    $reversal = $this->postingService->reverseJournal(
        (string) $original->id,
        '2026-01-20',
        'Correction of posting error'
    );

    $original->refresh();

    expect($original->status)->toBe('reversed')
        ->and($reversal->journal_type)->toBe('reversing')
        ->and($reversal->status)->toBe('posted')
        ->and($reversal->lines)->toHaveCount(2);

    $cashLine = $reversal->lines->firstWhere('account_id', $this->cashAccount->id);
    expect((float) $cashLine->credit_amount)->toBe(200.00); // Inverted to credit
});

test('posting engine generates subledger items when reconciliation accounts are used', function () {
    $arAccount = ChartOfAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'account_code' => '1100',
        'name' => 'Accounts Receivable',
        'account_class' => 'asset',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
        'reconciliation_type' => 'customer',
        'is_reconciliation_account' => true,
        'is_active' => true,
    ]);

    $customerId = (string) Str::uuid7();

    $entry = $this->postingService->createAndPost([
        'tenant_id' => $this->tenantId,
        'fiscal_period_id' => $this->period->id,
        'entry_number' => 'JE-AR-001',
        'description' => 'Customer Invoice',
        'lines' => [
            [
                'account_id' => $arAccount->id,
                'partner_type' => 'customer',
                'partner_id' => $customerId,
                'debit_amount' => 1000.00,
                'credit_amount' => 0.00,
            ],
            [
                'account_id' => $this->revenueAccount->id,
                'debit_amount' => 0.00,
                'credit_amount' => 1000.00,
            ],
        ],
    ]);

    $customerItem = CustomerLedger::where('journal_entry_id', $entry->id)->first();
    expect($customerItem)->not->toBeNull()
        ->and((float) $customerItem->open_amount)->toBe(1000.00)
        ->and($customerItem->customer_id)->toBe($customerId);
});
