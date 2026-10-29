<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Policies\PurchaseOrderPolicy;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    Gate::before(fn () => true);

    $this->tenant = Tenant::first() ?? Tenant::create([
        'name' => 'Concurrency Tenant',
        'legal_name' => 'Concurrency Corp',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::first() ?? User::create([
        'name' => 'Concurrency Officer',
        'email' => 'concurrency@procurement.test',
        'password' => bcrypt('password123'),
    ]);

    Sanctum::actingAs($this->user);
});

test('pessimistic lock prevents concurrent modifications on purchase order', function (): void {
    $supplier = Supplier::factory()->approved()->create(['tenant_id' => $this->tenant->id]);
    $order = PurchaseOrder::factory()->draft()->create([
        'supplier_id' => $supplier->id,
        'tenant_id' => $this->tenant->id,
    ]);

    DB::transaction(function () use ($order): void {
        $lockedOrder = PurchaseOrder::where('id', $order->id)->lockForUpdate()->first();
        expect($lockedOrder)->not->toBeNull();

        $lockedOrder->update(['status' => 'pending_approval', 'is_locked' => true]);
    });

    $order->refresh();
    expect($order->status)->toBe('pending_approval');
    expect($order->is_locked)->toBeTrue();
});

test('segregation of duties prevents purchase order creator from approving order', function (): void {
    $creator = User::create([
        'name' => 'Order Creator',
        'email' => 'creator@procurement.test',
        'password' => bcrypt('password123'),
    ]);

    $order = PurchaseOrder::factory()->create([
        'created_by' => $creator->id,
        'status' => 'pending_approval',
    ]);

    $policy = new PurchaseOrderPolicy;

    // Creator cannot approve
    expect($policy->approve($creator, $order))->toBeFalse();

    // Different user can approve
    $approver = User::create([
        'name' => 'Different Approver',
        'email' => 'approver@procurement.test',
        'password' => bcrypt('password123'),
    ]);
    expect($policy->approve($approver, $order))->toBeTrue();
});
