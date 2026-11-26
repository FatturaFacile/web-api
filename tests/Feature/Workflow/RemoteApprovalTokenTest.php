<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Supplier;
use Modules\Workflow\Models\ApprovalToken;
use Modules\Workflow\Services\ExternalActionTokenService;

require_once __DIR__.'/Support/ApprovalEngineHelpers.php';

beforeEach(function (): void {
    $this->tenant = wfTenant();
    $this->requester = wfMember($this->tenant, 'member', 'Requester');
    $this->alice = wfMember($this->tenant, 'member', 'Alice');
    $this->bob = wfMember($this->tenant, 'member', 'Bob');
    $this->document = wfMember($this->tenant, 'member', 'Document 4711');
    wfDefinition($this->tenant, 'WF_TOKEN', ['assignee_type' => 'direct_user', 'assignee_id' => $this->alice->id]);

    $this->tokens = app(ExternalActionTokenService::class);
    $this->instance = wfStart($this->tenant, 'WF_TOKEN', $this->document, $this->requester);
    $this->step = $this->instance->steps()->firstOrFail();

    // A token as it travels in an email: the raw secret is in the link only.
    $this->secret = function (string $action = 'approve', $user = null, $step = null): string {
        $url = $this->tokens->generateActionToken($step ?? $this->step, $user ?? $this->alice, $action);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query['token'];
    };
});

afterEach(fn () => app(TenantContext::class)->clear());

test('the confirmation page reads the token without deciding anything', function (): void {
    $secret = ($this->secret)();

    $this->getJson("/api/v1/workflows/remote-approval/{$secret}?action=approve")
        ->assertOk()
        ->assertJsonPath('data.requires_reauth', false)
        ->assertJsonPath('data.action', 'approve')
        ->assertJsonPath('data.subject_label', 'Document 4711')
        ->assertJsonPath('data.requester_name', 'Requester')
        ->assertJsonPath('data.approver_name', 'Alice');

    expect($this->step->refresh()->status)->toBe('pending')
        ->and(ApprovalToken::query()->whereNotNull('used_at')->count())->toBe(0);

    $this->getJson("/api/v1/workflows/remote-approval/{$secret}?action=approve")->assertOk();
});

test('a token lives 72 hours by default', function (): void {
    ($this->secret)();

    $token = ApprovalToken::query()->firstOrFail();

    expect($token->expires_at->diffInHours(now(), true))->toBeGreaterThanOrEqual(71)->toBeLessThanOrEqual(72);
});

test('the decision needs the confirmation of the approver', function (): void {
    $secret = ($this->secret)();

    $this->postJson("/api/v1/workflows/remote-approval/{$secret}/confirm", ['action' => 'approve'])->assertUnprocessable()->assertJsonValidationErrors('confirm');
    $this->postJson("/api/v1/workflows/remote-approval/{$secret}/confirm", ['action' => 'approve', 'confirm' => false])->assertUnprocessable();

    expect($this->step->refresh()->status)->toBe('pending');

    $this->postJson("/api/v1/workflows/remote-approval/{$secret}/confirm", ['action' => 'approve', 'confirm' => true, 'comment' => 'From my phone'])
        ->assertOk()
        ->assertJsonPath('step_id', (string) $this->step->id)
        ->assertJsonPath('status', 'approved');

    expect($this->instance->refresh()->status)->toBe('approved');
});

test('a link is single use: the second use answers 410 with no side effect', function (): void {
    $secret = ($this->secret)();
    $this->postJson("/api/v1/workflows/remote-approval/{$secret}/confirm", ['action' => 'approve', 'confirm' => true])->assertOk();
    $decisions = DB::table('approval_decisions')->count();

    $this->postJson("/api/v1/workflows/remote-approval/{$secret}/confirm", ['action' => 'approve', 'confirm' => true])->assertStatus(410)->assertJsonPath('code', 'used');
    $this->getJson("/api/v1/workflows/remote-approval/{$secret}")->assertStatus(410);

    expect(DB::table('approval_decisions')->count())->toBe($decisions);
});

test('an expired link answers 410 and decides nothing', function (): void {
    $secret = ($this->secret)();

    $this->travel(73)->hours();

    $this->postJson("/api/v1/workflows/remote-approval/{$secret}/confirm", ['action' => 'approve', 'confirm' => true])->assertStatus(410)->assertJsonPath('code', 'expired');
    $this->getJson("/api/v1/workflows/remote-approval/{$secret}")->assertStatus(410);

    expect($this->step->refresh()->status)->toBe('pending');
});

test('an unknown secret answers 410', function (): void {
    $this->getJson('/api/v1/workflows/remote-approval/'.str_repeat('x', 64))->assertStatus(410)->assertJsonPath('code', 'invalid');
    $this->postJson('/api/v1/workflows/remote-approval/'.str_repeat('x', 64).'/confirm', ['action' => 'approve', 'confirm' => true])->assertStatus(410);
});

test('a link of another user answers 403 and the token stays usable', function (): void {
    $secret = ($this->secret)();

    Sanctum::actingAs($this->bob);
    $this->postJson("/api/v1/workflows/remote-approval/{$secret}/confirm", ['action' => 'approve', 'confirm' => true])->assertForbidden()->assertJsonPath('code', 'wrong_user');
    $this->getJson("/api/v1/workflows/remote-approval/{$secret}")->assertForbidden();

    expect($this->step->refresh()->status)->toBe('pending')
        ->and(ApprovalToken::query()->whereNotNull('used_at')->count())->toBe(0);
});

test('a link carries one action only and a rejection needs a reason', function (): void {
    $approve = ($this->secret)('approve');

    $this->postJson("/api/v1/workflows/remote-approval/{$approve}/confirm", ['action' => 'reject', 'confirm' => true, 'comment' => 'No'])->assertForbidden()->assertJsonPath('code', 'wrong_action');

    $reject = ($this->secret)('reject');
    $this->postJson("/api/v1/workflows/remote-approval/{$reject}/confirm", ['action' => 'reject', 'confirm' => true])->assertUnprocessable()->assertJsonValidationErrors('comment');
    $this->postJson("/api/v1/workflows/remote-approval/{$reject}/confirm", ['action' => 'reject', 'confirm' => true, 'comment' => 'Too expensive'])->assertOk()->assertJsonPath('status', 'rejected');

    expect($this->instance->refresh()->status)->toBe('rejected');
});

test('an amount above the threshold needs a fresh sign-in of the approver', function (): void {
    config(['workflow.token.reauth_threshold' => 1000]);
    $supplier = Supplier::factory()->approved()->create(['tenant_id' => $this->tenant->id]);
    $order = PurchaseOrder::factory()->create(['tenant_id' => $this->tenant->id, 'supplier_id' => $supplier->id, 'status' => 'draft', 'total_amount' => '5000.00', 'total_gross' => '5000.00', 'currency' => 'CHF']);
    $instance = wfStart($this->tenant, 'WF_TOKEN', $order, $this->requester);
    $step = $instance->steps()->firstOrFail();
    $secret = ($this->secret)('approve', $this->alice, $step);

    expect(ApprovalToken::query()->where('workflow_instance_step_id', $step->id)->firstOrFail()->requires_reauth)->toBeTrue();

    $this->getJson("/api/v1/workflows/remote-approval/{$secret}")->assertOk()->assertJsonPath('data.requires_reauth', true)->assertJsonPath('data.amount', 5000);

    // Anonymous: the link alone is not enough above the threshold.
    $this->postJson("/api/v1/workflows/remote-approval/{$secret}/confirm", ['action' => 'approve', 'confirm' => true])->assertForbidden()->assertJsonPath('code', 'reauth_required');

    Sanctum::actingAs($this->alice);
    $this->postJson("/api/v1/workflows/remote-approval/{$secret}/confirm", ['action' => 'approve', 'confirm' => true, 'password' => 'wrong'])->assertForbidden()->assertJsonPath('code', 'reauth_failed');

    expect($step->refresh()->status)->toBe('pending')
        ->and(ApprovalToken::query()->where('workflow_instance_step_id', $step->id)->firstOrFail()->used_at)->toBeNull();

    $this->postJson("/api/v1/workflows/remote-approval/{$secret}/confirm", ['action' => 'approve', 'confirm' => true, 'password' => 'Secret-passw0rd!'])->assertOk();

    expect($instance->refresh()->status)->toBe('approved');
});

test('a small amount needs no re-authentication', function (): void {
    config(['workflow.token.reauth_threshold' => 1000]);
    $secret = ($this->secret)();

    expect(ApprovalToken::query()->firstOrFail()->requires_reauth)->toBeFalse();
    $this->postJson("/api/v1/workflows/remote-approval/{$secret}/confirm", ['action' => 'approve', 'confirm' => true])->assertOk();
});

test('the signed legacy endpoint also needs the confirmation', function (): void {
    $url = $this->tokens->generateActionToken($this->step, $this->alice, 'approve');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    $this->postJson($url, ['token' => $query['token'], 'action' => 'approve'])->assertUnprocessable()->assertJsonValidationErrors('confirm');
    $this->postJson($url, ['token' => $query['token'], 'action' => 'approve', 'confirm' => true])->assertOk();
});
