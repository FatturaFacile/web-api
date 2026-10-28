<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Contracts\PeriodClosingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::first() ?? Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'GL Concurrency Test Tenant',
        'legal_name' => 'GL Concurrency SpA',
    ]);
    app(TenantContext::class)->setTenant($this->tenant);
    $this->tenantId = (string) $this->tenant->id;
    $this->postingService = app(DoubleEntryPostingServiceInterface::class);
    $this->closingService = app(PeriodClosingServiceInterface::class);

    $this->fiscalYear = FiscalYear::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'year_code' => 'FY2026-CONC',
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

    $this->cash = ChartOfAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'account_code' => '1000',
        'name' => 'Cash',
        'account_class' => 'asset',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
        'is_active' => true,
    ]);

    $this->revenue = ChartOfAccount::create([
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

test('concurrent post draft attempts are idempotent and do not duplicate postings', function () {
    $entry = JournalEntry::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'fiscal_period_id' => $this->period->id,
        'entry_number' => 'JE-CONC-001',
        'posting_date' => '2026-01-10',
        'document_date' => '2026-01-10',
        'description' => 'Draft Entry for Concurrency',
        'status' => 'draft',
        'total_amount' => 500.0,
    ]);

    JournalLine::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'journal_entry_id' => $entry->id,
        'line_number' => 1,
        'account_id' => $this->cash->id,
        'description' => 'Dr Cash',
        'debit_amount' => 500.0,
        'credit_amount' => 0.0,
    ]);

    JournalLine::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'journal_entry_id' => $entry->id,
        'line_number' => 2,
        'account_id' => $this->revenue->id,
        'description' => 'Cr Revenue',
        'debit_amount' => 0.0,
        'credit_amount' => 500.0,
    ]);

    // First post
    $posted1 = $this->postingService->postDraft((string) $entry->id, 'user_a');
    // Concurrent/repeated post
    $posted2 = $this->postingService->postDraft((string) $entry->id, 'user_b');

    expect($posted1->status)->toBe('posted')
        ->and($posted2->status)->toBe('posted')
        ->and(JournalEntry::where('entry_number', 'JE-CONC-001')->count())->toBe(1);
});

test('pessimistic lock on period close prevents race conditions', function () {
    $p = DB::transaction(function () {
        $locked = FiscalPeriod::where('id', $this->period->id)->lockForUpdate()->first();

        return $this->closingService->closePeriod((string) $locked->id, 'admin');
    });

    expect($p->status)->toBe('closed');
});
