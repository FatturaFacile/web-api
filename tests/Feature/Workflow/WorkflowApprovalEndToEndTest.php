<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Supplier;
use Modules\Workflow\Actions\SeedDefaultWorkflowDefinitionsAction;
use Modules\Workflow\Models\WorkflowInstance;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Approvazioni Srl', 'legal_name' => 'Approvazioni Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->setTenant($this->tenant);
    app(SeedDefaultWorkflowDefinitionsAction::class)->execute((string) $this->tenant->id, ['PURCHASE_ORDER_APPROVAL']);
    // The approved order commits its budget in the General Ledger.
    app(OpenFiscalYearAction::class)->execute((string) $this->tenant->id, (int) now()->year);

    $member = function (string $role): User {
        $user = User::create(['name' => ucfirst($role), 'email' => Str::random(10).'@example.it', 'password' => bcrypt('secret123'), 'tenant_id' => $this->tenant->id]);
        $user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

        return $user;
    };
    $this->requester = $member('member');
    $this->admin = $member('admin');

    $supplier = Supplier::factory()->approved()->create(['tenant_id' => $this->tenant->id]);
    $this->order = PurchaseOrder::factory()->create(['tenant_id' => $this->tenant->id, 'supplier_id' => $supplier->id, 'status' => 'pending_approval', 'is_locked' => true]);

    $this->startApproval = function (): array {
        Sanctum::actingAs($this->requester);
        $instance = $this->withHeader('X-Tenant-Id', $this->tenant->id)->postJson('/api/v1/workflows/instances', [
            'workflow_code' => 'PURCHASE_ORDER_APPROVAL', 'approvable_type' => PurchaseOrder::class, 'approvable_id' => $this->order->id,
        ])->assertCreated()->json();

        $step = WorkflowInstance::query()->findOrFail($instance['id'] ?? $instance['data']['id'])->steps()->firstOrFail();

        return [$instance['id'] ?? $instance['data']['id'], (string) $step->id];
    };
});

test('approving the default purchase order workflow over HTTP approves and unlocks the order through its listener', function (): void {
    [$instanceId, $stepId] = ($this->startApproval)();

    Sanctum::actingAs($this->admin);
    $this->postJson("/api/v1/workflows/steps/{$stepId}/decide", ['decision' => 'approved', 'comment' => 'ok'])->assertOk();

    $this->order->refresh();
    expect(WorkflowInstance::query()->findOrFail($instanceId)->status)->toBe('approved')
        ->and($this->order->status)->toBe('approved')
        ->and($this->order->is_locked)->toBeFalse();

    // The completion event reached its listeners once and was recorded once in the outbox.
    expect(TransactionalOutbox::query()->where('event_type', 'like', '%completed%')->where('aggregate_id', $instanceId)->count())->toBe(1);
});

test('rejecting the default purchase order workflow over HTTP rejects the order through its listener', function (): void {
    [$instanceId, $stepId] = ($this->startApproval)();

    Sanctum::actingAs($this->admin);
    $this->postJson("/api/v1/workflows/steps/{$stepId}/decide", ['decision' => 'rejected', 'comment' => 'no budget'])->assertOk();

    $this->order->refresh();
    expect(WorkflowInstance::query()->findOrFail($instanceId)->status)->toBe('rejected')
        ->and($this->order->status)->toBe('rejected')
        ->and($this->order->is_locked)->toBeFalse();
});
