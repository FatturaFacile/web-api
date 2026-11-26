<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Workflow\Actions\CreateDelegationAction;
use Modules\Workflow\DTOs\CreateDelegationDTO;
use Modules\Workflow\Models\ApprovalMatrix;
use Modules\Workflow\Models\ApprovalMatrixTier;
use Modules\Workflow\Models\BusinessCalendar;
use Modules\Workflow\Services\ExternalActionTokenService;

require_once __DIR__.'/Support/ApprovalEngineHelpers.php';

beforeEach(function (): void {
    // Tenant A owns everything below.
    $this->a = wfTenant('Alpha AG');
    $this->aRequester = wfMember($this->a, 'member', 'Alpha Requester');
    $this->aApprover = wfMember($this->a, 'member', 'Alpha Approver');
    $this->aAdmin = wfMember($this->a, 'admin', 'Alpha Admin');
    $this->aDocument = wfMember($this->a, 'member', 'Alpha Document');
    wfDefinition($this->a, 'WF_ALPHA', ['assignee_type' => 'direct_user', 'assignee_id' => $this->aApprover->id]);
    $this->aInstance = wfStart($this->a, 'WF_ALPHA', $this->aDocument, $this->aRequester);
    $this->aStep = $this->aInstance->steps()->firstOrFail();

    $this->aMatrix = ApprovalMatrix::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->a->id, 'code' => 'MX-ALPHA', 'name' => 'Alpha matrix', 'target_entity_type' => 'purchase_order', 'priority' => 100, 'is_active' => true]);
    ApprovalMatrixTier::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->a->id, 'approval_matrix_id' => $this->aMatrix->id, 'tier_level' => 1, 'approver_type' => 'role', 'approver_value' => 'admin', 'approval_type' => 'or_parallel']);
    $this->aCalendar = BusinessCalendar::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->a->id, 'name' => 'Alpha calendar', 'timezone' => 'Europe/Zurich']);
    $this->aDelegation = app(CreateDelegationAction::class)->execute(new CreateDelegationDTO(
        delegatorUserId: (string) $this->aApprover->id,
        delegateUserId: (string) $this->aAdmin->id,
        startsAt: now()->subHour(),
        endsAt: now()->addDays(2),
        tenantId: (string) $this->a->id,
    ));
    $url = app(ExternalActionTokenService::class)->generateActionToken($this->aStep, $this->aApprover, 'approve');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $this->aToken = $query['token'];

    // Tenant B has nothing of it.
    $this->b = wfTenant('Beta AG');
    $this->bAdmin = wfMember($this->b, 'admin', 'Beta Admin');
    $this->bMember = wfMember($this->b, 'member', 'Beta Member');

    $this->asB = function ($user = null) {
        Sanctum::actingAs($user ?? $this->bAdmin);
        app(TenantContext::class)->clear();

        return $this->withHeader('X-Tenant-Id', (string) $this->b->id);
    };
});

afterEach(fn () => app(TenantContext::class)->clear());

test('tenant B cannot read, change or act on the instances of tenant A', function (): void {
    ($this->asB)()->getJson("/api/v1/workflows/instances/{$this->aInstance->id}")->assertNotFound();
    ($this->asB)()->getJson("/api/v1/workflows/instances/{$this->aInstance->id}/timeline")->assertNotFound();
    ($this->asB)()->getJson("/api/v1/workflows/instances/{$this->aInstance->id}/evidence")->assertNotFound();
    ($this->asB)()->getJson("/api/v1/workflows/instances/{$this->aInstance->id}/audit-trail")->assertNotFound();
    ($this->asB)()->postJson("/api/v1/workflows/instances/{$this->aInstance->id}/withdraw")->assertNotFound();
    ($this->asB)()->postJson("/api/v1/workflows/instances/{$this->aInstance->id}/cancel", ['reason' => 'Not yours'])->assertNotFound();
    ($this->asB)()->postJson("/api/v1/workflows/steps/{$this->aStep->id}/decide", ['decision' => 'approved'])->assertNotFound();

    expect($this->aStep->refresh()->status)->toBe('pending');
    ($this->asB)()->getJson('/api/v1/workflows/instances')->assertOk()->assertJsonCount(0, 'data');
});

test('the inbox, the counter and the reports of tenant B show nothing of tenant A', function (): void {
    ($this->asB)()->getJson('/api/v1/workflows/my-pending')->assertOk()->assertJsonCount(0, 'data');
    ($this->asB)()->getJson('/api/v1/workflows/my-tasks')->assertOk()->assertJsonCount(0, 'data');
    ($this->asB)()->getJson('/api/v1/workflows/pending-count')->assertOk()->assertJsonPath('data.pending_count', 0);
    ($this->asB)()->getJson('/api/v1/workflows/sla-report')->assertOk()->assertJsonPath('data.totals.steps', 0);
    ($this->asB)()->getJson('/api/v1/workflows/definitions')->assertOk()->assertJsonCount(0, 'data');
});

test('the matrices and the calendars of tenant A are invisible and untouchable for tenant B', function (): void {
    ($this->asB)()->getJson('/api/v1/workflows/matrices')->assertOk()->assertJsonCount(0, 'data');
    ($this->asB)()->getJson("/api/v1/workflows/matrices/{$this->aMatrix->id}")->assertNotFound();
    ($this->asB)()->deleteJson("/api/v1/workflows/matrices/{$this->aMatrix->id}")->assertNotFound();
    ($this->asB)()->putJson("/api/v1/workflows/matrices/{$this->aMatrix->id}", [
        'name' => 'Hijacked', 'subject_type' => 'purchase_order', 'tiers' => [['tier_level' => 1, 'approver_type' => 'role', 'approver_value' => 'admin']],
    ])->assertNotFound();

    ($this->asB)()->getJson('/api/v1/workflows/calendars')->assertOk()->assertJsonCount(0, 'data');
    ($this->asB)()->getJson("/api/v1/workflows/calendars/{$this->aCalendar->id}/holidays")->assertNotFound();
    ($this->asB)()->postJson("/api/v1/workflows/calendars/{$this->aCalendar->id}/seed-holidays", ['country' => 'CH', 'year' => 2026])->assertNotFound();

    expect($this->aMatrix->refresh()->name)->toBe('Alpha matrix')
        ->and(ApprovalMatrix::withoutGlobalScopes()->where('tenant_id', $this->b->id)->count())->toBe(0);
});

test('the delegations of tenant A are invisible to tenant B and cannot be revoked by it', function (): void {
    ($this->asB)()->getJson('/api/v1/workflows/delegations')->assertOk()->assertJsonCount(0, 'data');
    ($this->asB)()->deleteJson("/api/v1/workflows/delegations/{$this->aDelegation->id}")->assertNotFound();

    expect($this->aDelegation->refresh()->is_active)->toBeTrue();
});

test('a delegation cannot name a member of another tenant', function (): void {
    ($this->asB)()->postJson('/api/v1/workflows/delegations', [
        'delegate_user_id' => (string) $this->aAdmin->id,
        'scope' => 'all',
        'starts_at' => now()->toDateString(),
        'ends_at' => now()->addDays(3)->toDateString(),
    ])->assertUnprocessable()->assertJsonValidationErrors('delegate_user_id');
});

test('a token of tenant A is not usable by a signed-in user of tenant B', function (): void {
    ($this->asB)()->getJson("/api/v1/workflows/remote-approval/{$this->aToken}")->assertForbidden();
    ($this->asB)()->postJson("/api/v1/workflows/remote-approval/{$this->aToken}/confirm", ['action' => 'approve', 'confirm' => true])->assertForbidden();

    expect($this->aStep->refresh()->status)->toBe('pending');
});

test('the owner of a step in tenant A decides it while tenant A is the active one', function (): void {
    Sanctum::actingAs($this->aApprover);
    app(TenantContext::class)->clear();

    $this->withHeader('X-Tenant-Id', (string) $this->a->id)->postJson("/api/v1/workflows/steps/{$this->aStep->id}/decide", ['decision' => 'approved'])->assertOk();

    expect($this->aInstance->refresh()->status)->toBe('approved');
});

test('the same definition code in two tenants stays apart', function (): void {
    wfDefinition($this->b, 'WF_ALPHA', ['assignee_type' => 'direct_user', 'assignee_id' => $this->bMember->id]);

    $document = wfMember($this->b, 'member', 'Beta Document');
    $instance = wfStart($this->b, 'WF_ALPHA', $document, $this->bMember);

    expect($instance->steps()->firstOrFail()->assigned_user_id)->toBe((string) $this->bMember->id)
        ->and($instance->tenant_id)->toBe((string) $this->b->id);
    ($this->asB)($this->bMember)->getJson('/api/v1/workflows/my-tasks')->assertOk()->assertJsonCount(1, 'data');
});
