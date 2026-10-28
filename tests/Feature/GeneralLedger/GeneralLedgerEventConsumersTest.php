<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\Inventory\Events\InventoryCostingReadyEvent;
use Modules\Procurement\Events\GoodsReceivedEvent;
use Modules\Procurement\Events\PurchaseLiabilityReadyEvent;
use Modules\Projects\Events\ProjectRevenueRecognitionReadyEvent;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::first() ?? Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'GL Consumers Test Tenant',
        'legal_name' => 'GL Consumers SpA',
    ]);
    app(TenantContext::class)->setTenant($this->tenant);
    $this->tenantId = (string) $this->tenant->id;

    $this->fiscalYear = FiscalYear::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'year_code' => 'FY2026-EVENTS',
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

    // Base accounts
    ChartOfAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'account_code' => '1200',
        'name' => 'Inventory Asset',
        'account_class' => 'asset',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
        'is_active' => true,
    ]);

    ChartOfAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'account_code' => '2010',
        'name' => 'GR/IR Clearing',
        'account_class' => 'liability',
        'account_type' => 'liability',
        'normal_balance' => 'credit',
        'is_active' => true,
    ]);
});

test('procurement GoodsReceivedEvent generates GR/IR accrual journal', function () {
    $receiptId = (string) Str::uuid7();

    event(new GoodsReceivedEvent(
        receiptId: $receiptId,
        receiptNumber: 'GR-2026-0099',
        purchaseOrderId: (string) Str::uuid7(),
        supplierDeliveryNoteRef: 'DDT-1234',
        receivedLines: [
            ['purchase_order_line_id' => (string) Str::uuid7(), 'quantity_received' => 10.0, 'quantity_accepted' => 10.0],
        ],
        tenantId: $this->tenantId,
        companyId: $this->tenantId
    ));

    $journal = JournalEntry::where('tenant_id', $this->tenantId)
        ->where('source_document_id', $receiptId)
        ->first();

    expect($journal)->not->toBeNull()
        ->and($journal->status)->toBe('posted')
        ->and($journal->isBalanced())->toBeTrue();
});

test('procurement PurchaseLiabilityReadyEvent generates AP liability journal with VAT', function () {
    $matchLogId = (string) Str::uuid7();

    event(new PurchaseLiabilityReadyEvent(
        matchLogId: $matchLogId,
        purchaseOrderId: (string) Str::uuid7(),
        supplierId: (string) Str::uuid7(),
        netAmount: 1000.0,
        taxAmount: 220.0,
        grossAmount: 1220.0,
        currency: 'EUR',
        vendorInvoiceNumber: 'INV-FORN-999',
        tenantId: $this->tenantId,
        companyId: $this->tenantId
    ));

    $journal = JournalEntry::where('tenant_id', $this->tenantId)
        ->where('source_document_id', $matchLogId)
        ->first();

    expect($journal)->not->toBeNull()
        ->and($journal->status)->toBe('posted')
        ->and((float) $journal->total_amount)->toBe(1220.0);
});

test('inventory InventoryCostingReadyEvent generates COGS and asset relief journal', function () {
    $movementId = (string) Str::uuid7();

    event(new InventoryCostingReadyEvent(
        stockItemId: (string) Str::uuid7(),
        movementId: $movementId,
        unitCost: 25.0,
        totalCost: 250.0,
        costingMethod: 'FIFO',
        costLayers: [],
        companyId: $this->tenantId,
        tenantId: $this->tenantId
    ));

    $journal = JournalEntry::where('tenant_id', $this->tenantId)
        ->where('source_document_id', $movementId)
        ->first();

    expect($journal)->not->toBeNull()
        ->and((float) $journal->total_amount)->toBe(250.0);
});

test('projects ProjectRevenueRecognitionReadyEvent generates POC ASC 606 revenue journal', function () {
    $recId = (string) Str::uuid7();

    event(new ProjectRevenueRecognitionReadyEvent(
        recognitionId: $recId,
        projectId: (string) Str::uuid7(),
        cumulativeRecognizedRevenue: 15000.0,
        periodRevenueAdjustment: 15000.0,
        costCompletionPercentage: 65.5,
        accountingPeriod: '2026-01',
        debitAccountCode: '1150',
        creditAccountCode: '4000',
        tenantId: $this->tenantId,
        companyId: $this->tenantId
    ));

    $journal = JournalEntry::where('tenant_id', $this->tenantId)
        ->where('source_document_id', $recId)
        ->first();

    expect($journal)->not->toBeNull()
        ->and((float) $journal->total_amount)->toBe(15000.0)
        ->and($journal->isBalanced())->toBeTrue();
});
