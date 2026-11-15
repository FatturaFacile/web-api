<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Modules\ServiceManagement\Events\LoanerOverdueEvent;
use Modules\ServiceManagement\Jobs\ScanOverdueLoanersJob;
use Modules\ServiceManagement\Models\DepotRepairRmaOrder;
use Modules\ServiceManagement\Models\Loaner;
use Modules\ServiceManagement\Models\SerializedEquipmentPassport;
use Modules\ServiceManagement\Services\LoanerService;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($this->tenant);

    $passport = SerializedEquipmentPassport::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->makeRma = fn (array $attributes): DepotRepairRmaOrder => DepotRepairRmaOrder::factory()->create(array_merge([
        'tenant_id' => $this->tenant->id,
        'equipment_passport_id' => $passport->id,
    ], $attributes));
});

test('the scan alerts once per overdue loaner and is idempotent', function () {
    Event::fake([LoanerOverdueEvent::class]);

    $returnedLongAgo = ($this->makeRma)(['status' => 'returned', 'returned_at' => now()->subDays(10)]);
    $overdue = Loaner::factory()->create(['tenant_id' => $this->tenant->id, 'rma_order_id' => $returnedLongAgo->id]);

    $returnedRecently = ($this->makeRma)(['status' => 'returned', 'returned_at' => now()->subDays(2)]);
    $withinGrace = Loaner::factory()->create(['tenant_id' => $this->tenant->id, 'rma_order_id' => $returnedRecently->id]);

    $stillInRepair = ($this->makeRma)(['status' => 'in_repair_testing']);
    $active = Loaner::factory()->create(['tenant_id' => $this->tenant->id, 'rma_order_id' => $stillInRepair->id]);

    $repairAccount = Loaner::factory()->create([
        'tenant_id' => $this->tenant->id,
        'rma_order_id' => $returnedLongAgo->id,
        'register_type' => 'conto_riparazione',
    ]);

    $this->artisan('esm:loaners:scan-overdue')->assertSuccessful();

    Event::assertDispatchedTimes(LoanerOverdueEvent::class, 1);
    Event::assertDispatched(LoanerOverdueEvent::class, fn (LoanerOverdueEvent $event): bool => $event->loanerId === $overdue->id
        && $event->tenantId === $this->tenant->id
        && $event->daysOutstanding === 20);

    expect($overdue->fresh()->overdue_alerted_at)->not->toBeNull()
        ->and($withinGrace->fresh()->overdue_alerted_at)->toBeNull()
        ->and($active->fresh()->overdue_alerted_at)->toBeNull()
        ->and($repairAccount->fresh()->overdue_alerted_at)->toBeNull();

    // Rerun: nothing new
    $this->artisan('esm:loaners:scan-overdue')->assertSuccessful();
    Event::assertDispatchedTimes(LoanerOverdueEvent::class, 1);
});

test('the scan works without a tenant context (scheduler) and honours the grace override', function () {
    Event::fake([LoanerOverdueEvent::class]);

    $rma = ($this->makeRma)(['status' => 'closed', 'returned_at' => now()->subDays(3)]);
    Loaner::factory()->create(['tenant_id' => $this->tenant->id, 'rma_order_id' => $rma->id]);

    app(TenantContext::class)->clear();

    (new ScanOverdueLoanersJob(null, 7))->handle(app(LoanerService::class));
    Event::assertNotDispatched(LoanerOverdueEvent::class);

    (new ScanOverdueLoanersJob(null, 1))->handle(app(LoanerService::class));
    Event::assertDispatchedTimes(LoanerOverdueEvent::class, 1);
});
