<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Treasury\Contracts\ReceivableCollectionServiceInterface;
use Modules\Treasury\Events\CollectionReceivedEvent;
use Modules\Treasury\Models\ReceivableCollection;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::first() ?? Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'Treasury Collection Tenant',
        'legal_name' => 'Treasury Collection SpA',
    ]);
    app(TenantContext::class)->setTenant($this->tenant);
    $this->tenantId = (string) $this->tenant->id;
    $this->collectionService = app(ReceivableCollectionServiceInterface::class);
});

test('can categorize receivables into correct aging buckets and calculate summary', function () {
    $customer1 = (string) Str::uuid7();

    // 1. Current receivable (due in 10 days)
    ReceivableCollection::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'company_id' => $this->tenantId,
        'customer_id' => $customer1,
        'invoice_id' => (string) Str::uuid7(),
        'expected_amount' => 10000.00,
        'collected_amount' => 0.00,
        'due_date' => now()->addDays(10)->toDateString(),
        'payment_method' => 'SEPA_DIRECT_DEBIT_CORE',
        'status' => 'PENDING',
        'aging_bucket' => 'CURRENT',
        'dunning_level' => 0,
    ]);

    // 2. Overdue 1-30 days (due 15 days ago)
    ReceivableCollection::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'company_id' => $this->tenantId,
        'customer_id' => $customer1,
        'invoice_id' => (string) Str::uuid7(),
        'expected_amount' => 5000.00,
        'collected_amount' => 0.00,
        'due_date' => now()->subDays(15)->toDateString(),
        'payment_method' => 'SEPA_DIRECT_DEBIT_CORE',
        'status' => 'OVERDUE',
        'aging_bucket' => '1_30',
        'dunning_level' => 1,
    ]);

    // 3. Overdue 61-90 days (due 75 days ago)
    ReceivableCollection::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'company_id' => $this->tenantId,
        'customer_id' => $customer1,
        'invoice_id' => (string) Str::uuid7(),
        'expected_amount' => 3000.00,
        'collected_amount' => 0.00,
        'due_date' => now()->subDays(75)->toDateString(),
        'payment_method' => 'SEPA_DIRECT_DEBIT_CORE',
        'status' => 'OVERDUE',
        'aging_bucket' => '61_90',
        'dunning_level' => 2,
    ]);

    $aging = $this->collectionService->getCustomerAging($this->tenantId);

    expect($aging)->toHaveKeys(['buckets', 'total_open_receivables', 'as_of_date'])
        ->and($aging['buckets']['current'])->toBeGreaterThanOrEqual(10000.00)
        ->and($aging['buckets']['1_30'])->toBeGreaterThanOrEqual(5000.00)
        ->and($aging['buckets']['61_90'])->toBe(3000.00)
        ->and($aging['total_open_receivables'])->toBeGreaterThanOrEqual(18000.00);
});

test('can record collection received and fire CollectionReceivedEvent', function () {
    Event::fake([CollectionReceivedEvent::class]);

    $item = ReceivableCollection::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'company_id' => $this->tenantId,
        'customer_id' => (string) Str::uuid7(),
        'invoice_id' => (string) Str::uuid7(),
        'expected_amount' => 8000.00,
        'collected_amount' => 0.00,
        'due_date' => now()->addDays(5)->toDateString(),
        'payment_method' => 'WIRE_INBOUND',
        'status' => 'PENDING',
        'aging_bucket' => 'CURRENT',
        'dunning_level' => 0,
    ]);

    // Full collection
    $updated = $this->collectionService->recordCollectionReceived($item->id, 8000.00, now()->toDateString());

    expect($updated->status)->toBe('collected')
        ->and((float) $updated->collected_amount)->toBe(8000.00)
        ->and($updated->collection_date)->not->toBeNull();

    Event::assertDispatched(CollectionReceivedEvent::class);
});

test('can evaluate dunning readiness for overdue items', function () {
    ReceivableCollection::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'company_id' => $this->tenantId,
        'customer_id' => (string) Str::uuid7(),
        'invoice_id' => (string) Str::uuid7(),
        'expected_amount' => 4200.00,
        'collected_amount' => 0.00,
        'due_date' => now()->subDays(25)->toDateString(),
        'payment_method' => 'SEPA_DIRECT_DEBIT_CORE',
        'status' => 'OVERDUE',
        'aging_bucket' => '1_30',
        'dunning_level' => 0,
    ]);

    $candidates = $this->collectionService->evaluateDunningReadiness($this->tenantId);

    expect(count($candidates))->toBeGreaterThanOrEqual(1);
    expect($candidates[0]->status)->toBe('overdue');
});
