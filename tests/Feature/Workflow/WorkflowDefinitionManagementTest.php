<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Supplier;
use Modules\Workflow\Actions\SeedDefaultWorkflowDefinitionsAction;
use Modules\Workflow\Models\WorkflowDefinition;
use Modules\Workflow\Services\WorkflowGraphValidator;

uses(RefreshDatabase::class);

function workflowMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role), 'email' => Str::random(10).'@example.it', 'password' => bcrypt('secret123'), 'tenant_id' => $tenant->id]);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function workflowApi(User $user, Tenant $tenant): mixed
{
    Sanctum::actingAs($user);
    test()->flushHeaders();

    return test()->withHeader('X-Tenant-Id', $tenant->id);
}

/**
 * @return array<string, mixed>
 */
function simpleDefinitionPayload(string $code = 'CUSTOM_APPROVAL', bool $publish = true): array
{
    return [
        'code' => $code,
        'name' => 'Custom approval',
        'target_entity_type' => 'purchase_order',
        'publish' => $publish,
        'nodes' => [
            ['node_key' => 'start', 'name' => 'Start', 'node_type' => 'start'],
            ['node_key' => 'manager', 'name' => 'Manager', 'node_type' => 'approval', 'approval_type' => 'single', 'assignee_type' => 'role', 'assignee_id' => 'admin'],
            ['node_key' => 'end', 'name' => 'End', 'node_type' => 'end'],
        ],
        'transitions' => [
            ['from' => 'start', 'to' => 'manager', 'action_trigger' => 'start'],
            ['from' => 'manager', 'to' => 'end', 'action_trigger' => 'approved'],
        ],
    ];
}

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Workflow Design Srl', 'legal_name' => 'Workflow Design Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->setTenant($this->tenant);
    $this->admin = workflowMember($this->tenant, 'admin');
});

test('an admin creates and publishes a definition with its graph in one request', function (): void {
    $response = workflowApi($this->admin, $this->tenant)->postJson('/api/v1/workflows/definitions', simpleDefinitionPayload())->assertCreated();

    expect($response->json('status'))->toBe('published')
        ->and($response->json('is_active'))->toBeTrue()
        ->and($response->json('version'))->toBe(1)
        ->and($response->json('nodes'))->toHaveCount(3)
        ->and($response->json('transitions'))->toHaveCount(2);

    // A new version of the same code becomes the active one once published.
    $second = $this->postJson('/api/v1/workflows/definitions', simpleDefinitionPayload())->assertCreated();
    expect($second->json('version'))->toBe(2)
        ->and(WorkflowDefinition::query()->where('code', 'CUSTOM_APPROVAL')->where('is_active', true)->pluck('version')->all())->toBe([2]);

    $this->getJson('/api/v1/workflows/definitions/'.$second->json('id'))->assertOk()->assertJsonCount(3, 'nodes');
});

test('a draft graph is built step by step, validated on publish and frozen afterwards', function (): void {
    $api = workflowApi($this->admin, $this->tenant);
    $id = $api->postJson('/api/v1/workflows/definitions', ['code' => 'STEPWISE_APPROVAL', 'name' => 'Stepwise', 'target_entity_type' => 'project'])
        ->assertCreated()->assertJsonPath('status', 'draft')->json('id');

    $start = $this->postJson("/api/v1/workflows/definitions/{$id}/nodes", ['node_key' => 'start', 'name' => 'Start', 'node_type' => 'start'])->assertCreated()->json('data.id');
    $approval = $this->postJson("/api/v1/workflows/definitions/{$id}/nodes", ['node_key' => 'approval', 'name' => 'Approval', 'node_type' => 'approval', 'assignee_type' => 'role'])->assertCreated()->json('data.id');
    $end = $this->postJson("/api/v1/workflows/definitions/{$id}/nodes", ['node_key' => 'end', 'name' => 'End', 'node_type' => 'end'])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/workflows/definitions/{$id}/nodes", ['node_key' => 'end', 'name' => 'Duplicate', 'node_type' => 'end'])->assertUnprocessable();

    $this->postJson("/api/v1/workflows/definitions/{$id}/transitions", ['from_node_id' => $start, 'to_node_id' => $approval, 'action_trigger' => 'start'])->assertCreated();

    // Missing assignee, missing approved transition and an unreachable end node.
    $problems = $this->postJson("/api/v1/workflows/definitions/{$id}/publish")->assertUnprocessable()->json('errors.graph');
    expect(implode(' | ', $problems))->toContain('needs an assignee')->toContain('"approved" transition')->toContain('[end] cannot be reached');

    $this->patchJson("/api/v1/workflows/definitions/{$id}/nodes/{$approval}", ['assignee_id' => 'admin'])->assertOk()->assertJsonPath('data.assignee_id', 'admin');
    $transition = $this->postJson("/api/v1/workflows/definitions/{$id}/transitions", ['from_node_id' => $approval, 'to_node_id' => $end, 'action_trigger' => 'approved'])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/workflows/definitions/{$id}/publish")->assertOk()->assertJsonPath('status', 'published')->assertJsonPath('is_active', true);

    $this->postJson("/api/v1/workflows/definitions/{$id}/nodes", ['node_key' => 'extra', 'name' => 'Extra', 'node_type' => 'end'])->assertUnprocessable();
    $this->deleteJson("/api/v1/workflows/definitions/{$id}/transitions/{$transition}")->assertUnprocessable();
    $this->patchJson("/api/v1/workflows/definitions/{$id}", ['name' => 'Renamed', 'is_active' => false])->assertOk()->assertJsonPath('name', 'Renamed')->assertJsonPath('is_active', false);
});

test('a definition with running instances cannot be deleted, others are archived', function (): void {
    $api = workflowApi($this->admin, $this->tenant);
    $id = $api->postJson('/api/v1/workflows/definitions', simpleDefinitionPayload('DELETABLE_APPROVAL'))->assertCreated()->json('id');

    $supplier = Supplier::factory()->approved()->create(['tenant_id' => $this->tenant->id]);
    $order = PurchaseOrder::factory()->create(['tenant_id' => $this->tenant->id, 'supplier_id' => $supplier->id, 'status' => 'pending_approval']);
    $this->postJson('/api/v1/workflows/instances', ['workflow_code' => 'DELETABLE_APPROVAL', 'approvable_type' => PurchaseOrder::class, 'approvable_id' => $order->id])->assertCreated();

    $this->deleteJson("/api/v1/workflows/definitions/{$id}")->assertUnprocessable();

    $draft = $this->postJson('/api/v1/workflows/definitions', simpleDefinitionPayload('DRAFT_ONLY', false))->assertCreated()->json('id');
    $this->deleteJson("/api/v1/workflows/definitions/{$draft}")->assertNoContent();
    $this->getJson("/api/v1/workflows/definitions/{$draft}")->assertNotFound();
});

test('only owners and admins write definitions, every member reads them, other tenants see nothing', function (): void {
    $id = workflowApi($this->admin, $this->tenant)->postJson('/api/v1/workflows/definitions', simpleDefinitionPayload())->assertCreated()->json('id');

    $member = workflowMember($this->tenant, 'member');
    workflowApi($member, $this->tenant)->postJson('/api/v1/workflows/definitions', simpleDefinitionPayload('OTHER_APPROVAL'))->assertForbidden();
    $this->getJson("/api/v1/workflows/definitions/{$id}")->assertOk();

    $owner = workflowMember($this->tenant, 'owner');
    workflowApi($owner, $this->tenant)->patchJson("/api/v1/workflows/definitions/{$id}", ['description' => 'by the owner'])->assertOk();

    $foreign = Tenant::create(['name' => 'Foreign Srl', 'legal_name' => 'Foreign Srl']);
    workflowApi(workflowMember($foreign, 'admin'), $foreign)->getJson("/api/v1/workflows/definitions/{$id}")->assertNotFound();
    $this->patchJson("/api/v1/workflows/definitions/{$id}", ['name' => 'Hijack'])->assertNotFound();
});

test('the default definitions cover the workflow codes used by the modules, are valid and are seeded once', function (): void {
    $this->artisan('workflows:seed-defaults', ['--tenant' => $this->tenant->id])->assertSuccessful();

    $definitions = WorkflowDefinition::query()->where('tenant_id', $this->tenant->id)->get();
    expect($definitions)->toHaveCount(count(SeedDefaultWorkflowDefinitionsAction::Defaults));

    foreach ($definitions as $definition) {
        expect(app(WorkflowGraphValidator::class)->problems($definition))->toBe([])
            ->and($definition->is_active)->toBeTrue();
    }

    $this->artisan('workflows:seed-defaults', ['--tenant' => $this->tenant->id])->assertSuccessful();
    expect(WorkflowDefinition::query()->where('tenant_id', $this->tenant->id)->count())->toBe(count(SeedDefaultWorkflowDefinitionsAction::Defaults));

    // Every literal workflow code started in modules/ has a default.
    $codes = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('modules'), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php' && preg_match_all("/workflowCode:\s*'([A-Z_]+)'|WorkflowCode = '([A-Z_]+)'|WORKFLOW_CODE = '([A-Z_]+)'/", (string) file_get_contents($file->getPathname()), $matches)) {
            $codes = [...$codes, ...array_filter([...$matches[1], ...$matches[2], ...$matches[3]])];
        }
    }

    expect(array_values(array_diff(array_unique($codes), array_keys(SeedDefaultWorkflowDefinitionsAction::Defaults))))->toBe([]);
});
