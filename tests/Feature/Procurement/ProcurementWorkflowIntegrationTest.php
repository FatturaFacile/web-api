<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Supplier;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;
use Modules\Workflow\Models\WorkflowInstance;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    Gate::before(fn () => true);

    $this->tenant = Tenant::first() ?? Tenant::create([
        'name' => 'Workflow Procurement Tenant',
        'legal_name' => 'Workflow Procurement Corp',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::first() ?? User::create([
        'name' => 'Procurement Officer',
        'email' => 'officer@procurement.test',
        'password' => bcrypt('password123'),
    ]);

    Sanctum::actingAs($this->user);
});

test('workflow engine v2 approval unlocks purchase order and updates status to approved', function (): void {
    $supplier = Supplier::factory()->approved()->create(['tenant_id' => $this->tenant->id]);
    $order = PurchaseOrder::factory()->create([
        'tenant_id' => $this->tenant->id,
        'supplier_id' => $supplier->id,
        'status' => 'pending_approval',
        'is_locked' => true,
    ]);

    $instance = WorkflowInstance::create([
        'tenant_id' => $this->tenant->id,
        'workflow_definition_id' => (string) Str::uuid(),
        'workflow_code' => 'PURCHASE_ORDER_APPROVAL',
        'subject_type' => PurchaseOrder::class,
        'subject_id' => $order->id,
        'current_step_code' => 'APPROVAL_STEP_1',
        'status' => 'completed',
        'is_locked' => false,
        'assigned_to_user_id' => $this->user->id,
    ]);

    event(new WorkflowInstanceCompletedEvent(
        instanceId: (string) $instance->id,
        status: 'completed',
        tenantId: (string) $this->tenant->id,
        companyId: ''
    ));

    $order->refresh();
    expect($order->status)->toBe('approved');
    expect($order->is_locked)->toBeFalse();
});

test('workflow engine v2 rejection unlocks purchase order and updates status to rejected', function (): void {
    $supplier = Supplier::factory()->approved()->create(['tenant_id' => $this->tenant->id]);
    $order = PurchaseOrder::factory()->create([
        'tenant_id' => $this->tenant->id,
        'supplier_id' => $supplier->id,
        'status' => 'pending_approval',
        'is_locked' => true,
    ]);

    $instance = WorkflowInstance::create([
        'tenant_id' => $this->tenant->id,
        'workflow_definition_id' => (string) Str::uuid(),
        'workflow_code' => 'PURCHASE_ORDER_APPROVAL',
        'subject_type' => PurchaseOrder::class,
        'subject_id' => $order->id,
        'current_step_code' => 'APPROVAL_STEP_1',
        'status' => 'rejected',
        'is_locked' => false,
    ]);

    event(new WorkflowInstanceRejectedEvent(
        instanceId: (string) $instance->id,
        status: 'rejected',
        tenantId: (string) $this->tenant->id,
        companyId: ''
    ));

    $order->refresh();
    expect($order->status)->toBe('rejected');
    expect($order->is_locked)->toBeFalse();
});
