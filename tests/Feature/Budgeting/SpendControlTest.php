<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Budgeting\Domain\SpendDecision;
use Modules\Budgeting\Domain\SpendRequest;
use Modules\Budgeting\Exceptions\SpendLockException;
use Modules\Budgeting\Models\BudgetCommitment;
use Modules\Budgeting\Models\BudgetLine;
use Modules\Budgeting\Models\BudgetOverride;
use Modules\Budgeting\Models\BudgetSpendCheck;
use Modules\Budgeting\Models\BudgetSpendRule;
use Modules\Budgeting\Models\BudgetVersion;
use Modules\Budgeting\Services\CommitmentService;
use Modules\Budgeting\Services\SpendControlService;

require_once __DIR__.'/Support/ControlHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = ctlTenant();
    $this->owner = ctlMember($this->tenant, 'owner');
    ctlAct($this->owner, $this->tenant);
    $this->expense = ctlAccount($this->tenant);
    $this->center = ctlCostCenter($this->tenant);
    // 1,000 a month: 12,000 for the year on the cost centre.
    $this->budget = ctlApprovedBudget($this->tenant, $this->expense, $this->center, '1000.00');
});

afterEach(fn () => app(TenantContext::class)->clear());

function ctlRequest(string $tenantId, string $centerId, string $amount, string $stage = 'submit', string $type = 'purchase_order', ?string $sourceId = null): SpendRequest
{
    return new SpendRequest($tenantId, $type, $sourceId ?? (string) Str::uuid(), 'DOC-1', $stage, $amount, null, $centerId, null, Carbon::parse('2028-06-15'));
}

function ctlCommit(string $tenantId, string $centerId, string $amount, ?string $sourceId = null): BudgetCommitment
{
    return BudgetCommitment::create([
        'tenant_id' => $tenantId, 'source_type' => 'purchase_order', 'source_id' => $sourceId ?? (string) Str::uuid(), 'source_number' => 'PO-1', 'cost_center_id' => $centerId,
        'fiscal_year' => 2028, 'period' => 3, 'commitment_date' => '2028-03-10', 'currency' => 'EUR', 'amount' => $amount, 'status' => 'open',
    ]);
}

test('available budget is the budget less actual costs and open commitments', function (): void {
    ctlPost($this->tenant, $this->expense, '2028-02-10', '2000.00', $this->center);
    ctlCommit($this->tenant->id, $this->center->id, '1500.00');
    // A released commitment and a consumed part do not count.
    ctlCommit($this->tenant->id, $this->center->id, '900.00')->update(['status' => 'released']);
    ctlCommit($this->tenant->id, $this->center->id, '1000.00')->update(['consumed_amount' => '400.00']);

    $data = $this->getJson("/api/v1/budgeting/spend-control/availability?date=2028-06-01&cost_center_id={$this->center->id}")->assertOk()->json('data');

    expect($data['has_budget'])->toBeTrue()
        ->and($data['budget'])->toBe('12000.00')
        ->and($data['actual'])->toBe('2000.00')
        ->and($data['committed'])->toBe('2100.00')
        ->and($data['available'])->toBe('7900.00')
        ->and($data['utilization_percent'])->toBe('34.17')
        ->and($data['fiscal_year'])->toBe(2028);
});

test('revenue lines and other cost centres stay out of the available budget', function (): void {
    $revenue = ctlAccount($this->tenant, 'revenue');
    BudgetVersion::query()->whereKey($this->budget->id)->update(['status' => 'draft']);
    BudgetLine::create(['tenant_id' => $this->tenant->id, 'version_id' => $this->budget->id, 'account_id' => $revenue->id, 'cost_center_id' => $this->center->id, 'period' => 1, 'amount' => '50000.00', 'source' => 'manual']);
    BudgetVersion::query()->whereKey($this->budget->id)->update(['status' => 'approved']);
    $other = ctlCostCenter($this->tenant);

    $this->getJson("/api/v1/budgeting/spend-control/availability?date=2028-06-01&cost_center_id={$this->center->id}")->assertOk()->assertJsonPath('data.budget', '12000.00');
    $this->getJson("/api/v1/budgeting/spend-control/availability?date=2028-06-01&cost_center_id={$other->id}")->assertOk()->assertJsonPath('data.has_budget', false)->assertJsonPath('data.budget', '0.00');
});

test('a scope without an approved budget has no budget and a draft budget does not govern', function (): void {
    $this->budget->update(['status' => 'draft']);

    $this->getJson("/api/v1/budgeting/spend-control/availability?date=2028-06-01&cost_center_id={$this->center->id}")->assertOk()->assertJsonPath('data.has_budget', false)->assertJsonPath('data.version_id', null);
});

test('the latest approved revision governs instead of the original budget', function (): void {
    $revised = ctlApprovedBudget($this->tenant, $this->expense, $this->center, '2000.00', 'revised', 'Budget 2028 revision');
    $revised->update(['revision_number' => 1, 'base_version_id' => $this->budget->id]);

    $this->getJson("/api/v1/budgeting/spend-control/availability?date=2028-06-01&cost_center_id={$this->center->id}")->assertOk()
        ->assertJsonPath('data.budget', '24000.00')->assertJsonPath('data.version_id', $revised->id);
});

test('a hard block rule blocks a spend over the budget and reports the shortfall', function (): void {
    ctlRule($this->tenant, 'hard_block');
    ctlPost($this->tenant, $this->expense, '2028-02-10', '2000.00', $this->center);
    ctlCommit($this->tenant->id, $this->center->id, '1500.00');

    $inside = $this->postJson('/api/v1/budgeting/spend-control/check', ['source_type' => 'purchase_order', 'amount' => '8500', 'cost_center_id' => $this->center->id, 'date' => '2028-06-15'])->assertOk()->json('data');
    $over = $this->postJson('/api/v1/budgeting/spend-control/check', ['source_type' => 'purchase_order', 'amount' => '9000', 'cost_center_id' => $this->center->id, 'date' => '2028-06-15'])->assertOk()->json('data');

    expect($inside['outcome'])->toBe('ok')->and($inside['shortfall'])->toBe('0.00')
        ->and($over['outcome'])->toBe('blocked')->and($over['shortfall'])->toBe('500.00')->and($over['mode'])->toBe('hard_block')
        ->and($over['availability']['available'])->toBe('8500.00');
    // A what-if records nothing.
    expect(BudgetSpendCheck::query()->count())->toBe(0);
});

test('the threshold of the rule moves the limit below the budget', function (): void {
    ctlRule($this->tenant, 'hard_block', ['threshold_percent' => '80']);
    ctlCommit($this->tenant->id, $this->center->id, '3500.00');

    // The limit is 9,600: 3,500 committed leaves 6,100.
    $ok = app(SpendControlService::class)->evaluate(ctlRequest($this->tenant->id, $this->center->id, '6100.00'), false);
    $blocked = app(SpendControlService::class)->evaluate(ctlRequest($this->tenant->id, $this->center->id, '6200.00'), false);

    expect($ok->outcome)->toBe(SpendDecision::Ok)->and($blocked->outcome)->toBe(SpendDecision::Blocked)->and($blocked->shortfall)->toBe('100.00');
});

test('enforcing a blocked spend raises the lock exception and logs it, a warning passes and is logged', function (): void {
    $block = ctlRule($this->tenant, 'hard_block', ['cost_center_id' => $this->center->id, 'name' => 'Centre lock']);
    $service = app(SpendControlService::class);
    $request = ctlRequest($this->tenant->id, $this->center->id, '13000.00');

    expect(fn () => $service->enforce($request))->toThrow(SpendLockException::class);
    $log = BudgetSpendCheck::query()->sole();
    expect($log->outcome)->toBe('blocked')->and($log->rule_id)->toBe($block->id)->and($log->requested_amount)->toBe('13000.00')->and($log->available_amount)->toBe('12000.00');

    $block->update(['mode' => 'warning']);
    $decision = $service->enforce($request);
    expect($decision->outcome)->toBe(SpendDecision::Warning)->and(BudgetSpendCheck::query()->where('outcome', 'warning')->count())->toBe(1);

    $block->update(['mode' => 'allow']);
    expect($service->enforce($request)->outcome)->toBe(SpendDecision::Ok)->and(BudgetSpendCheck::query()->count())->toBe(2);
});

test('the most specific rule wins and rules only apply to their document types and stages', function (): void {
    ctlRule($this->tenant, 'allow', ['name' => 'Company default']);
    ctlRule($this->tenant, 'hard_block', ['name' => 'Centre', 'cost_center_id' => $this->center->id, 'document_types' => ['purchase_order'], 'stages' => ['approve']]);
    $service = app(SpendControlService::class);

    expect($service->evaluate(ctlRequest($this->tenant->id, $this->center->id, '20000', 'approve'), false)->outcome)->toBe(SpendDecision::Blocked)
        ->and($service->evaluate(ctlRequest($this->tenant->id, $this->center->id, '20000', 'submit'), false)->outcome)->toBe(SpendDecision::Ok)
        ->and($service->evaluate(ctlRequest($this->tenant->id, $this->center->id, '20000', 'approve', 'requisition'), false)->outcome)->toBe(SpendDecision::Ok);
});

test('without budget the spend is free unless the rule enforces it', function (): void {
    $other = ctlCostCenter($this->tenant);
    $rule = ctlRule($this->tenant, 'hard_block');
    $service = app(SpendControlService::class);

    expect($service->evaluate(ctlRequest($this->tenant->id, $other->id, '100'), false)->outcome)->toBe(SpendDecision::Ok);

    $rule->update(['enforce_without_budget' => true]);
    $decision = $service->evaluate(ctlRequest($this->tenant->id, $other->id, '100'), false);
    expect($decision->outcome)->toBe(SpendDecision::Blocked)->and($decision->shortfall)->toBe('100.00')->and($decision->availability->hasBudget)->toBeFalse();
});

test('an exhausted budget has no availability and a zero amount still passes', function (): void {
    ctlRule($this->tenant, 'hard_block');
    ctlCommit($this->tenant->id, $this->center->id, '12000.00');
    $service = app(SpendControlService::class);

    $data = $this->getJson("/api/v1/budgeting/spend-control/availability?date=2028-06-01&cost_center_id={$this->center->id}")->assertOk()->json('data');
    expect($data['available'])->toBe('0.00')
        ->and($service->evaluate(ctlRequest($this->tenant->id, $this->center->id, '0.00'), false)->outcome)->toBe(SpendDecision::Ok)
        ->and($service->evaluate(ctlRequest($this->tenant->id, $this->center->id, '0.01'), false)->outcome)->toBe(SpendDecision::Blocked);
});

test('a purchase order that replaces a requisition does not count it twice', function (): void {
    ctlRule($this->tenant, 'hard_block');
    $requisitionId = (string) Str::uuid();
    BudgetCommitment::create(['tenant_id' => $this->tenant->id, 'source_type' => 'requisition', 'source_id' => $requisitionId, 'source_number' => 'REQ-1', 'cost_center_id' => $this->center->id, 'fiscal_year' => 2028, 'commitment_date' => '2028-03-10', 'currency' => 'EUR', 'amount' => '12000.00', 'status' => 'open']);
    $service = app(SpendControlService::class);

    $plain = ctlRequest($this->tenant->id, $this->center->id, '12000.00');
    $replacing = new SpendRequest($this->tenant->id, 'purchase_order', (string) Str::uuid(), 'PO-9', 'approve', '12000.00', null, $this->center->id, null, Carbon::parse('2028-06-15'), null, $requisitionId);

    expect($service->evaluate($plain, false)->outcome)->toBe(SpendDecision::Blocked)
        ->and($service->evaluate($replacing, false)->outcome)->toBe(SpendDecision::Ok);
});

test('an override is requested by a member, decided by the CFO and honoured once', function (): void {
    ctlRule($this->tenant, 'hard_block');
    $buyer = ctlMember($this->tenant, 'member');
    $documentId = (string) Str::uuid();
    ctlAct($buyer, $this->tenant);

    $override = $this->postJson('/api/v1/budgeting/spend-control/overrides', [
        'scope' => 'document', 'source_type' => 'purchase_order', 'source_id' => $documentId, 'source_number' => 'PO-77', 'cost_center_id' => $this->center->id,
        'requested_amount' => '13000', 'reason' => 'Urgent replacement of the line', 'date' => '2028-06-15',
    ])->assertCreated()->json('data');

    expect($override['status'])->toBe('pending')->and($override['shortfall_amount'])->toBe('1000.00')->and($override['requested_by'])->toBe($buyer->id);
    $this->postJson('/api/v1/budgeting/spend-control/overrides', ['scope' => 'document', 'source_type' => 'purchase_order', 'source_id' => $documentId, 'requested_amount' => '13000', 'reason' => 'Again please'])->assertUnprocessable()->assertJsonValidationErrors('source_id');
    $this->postJson("/api/v1/budgeting/spend-control/overrides/{$override['id']}/approve")->assertForbidden();

    $service = app(SpendControlService::class);
    $request = ctlRequest($this->tenant->id, $this->center->id, '13000.00', 'submit', 'purchase_order', $documentId);
    expect($service->evaluate($request, false)->outcome)->toBe(SpendDecision::Blocked);

    ctlAct($this->owner, $this->tenant);
    $approved = $this->postJson("/api/v1/budgeting/spend-control/overrides/{$override['id']}/approve", ['comment' => 'Approved for the line stop'])->assertOk()->json('data');
    expect($approved['status'])->toBe('approved')->and($approved['approved_amount'])->toBe('1000.00')->and($approved['decided_by'])->toBe($this->owner->id)
        ->and($approved['valid_until'])->toBe(now()->addDays(30)->toDateString());

    $decision = $service->enforce($request);
    expect($decision->outcome)->toBe(SpendDecision::Overridden)->and($decision->overrideId)->toBe($override['id']);
    $this->postJson("/api/v1/budgeting/spend-control/overrides/{$override['id']}/reject", ['comment' => 'Too late'])->assertUnprocessable();

    // Another document is not covered, and the document override is used up when the commitment is written.
    expect($service->evaluate(ctlRequest($this->tenant->id, $this->center->id, '13000.00', 'submit', 'purchase_order', (string) Str::uuid()), false)->outcome)->toBe(SpendDecision::Blocked);
    app(CommitmentService::class)->sync([
        'tenant_id' => $this->tenant->id, 'source_type' => 'purchase_order', 'source_id' => $documentId, 'source_number' => 'PO-77', 'parent_source_id' => null, 'account_id' => null,
        'cost_center_id' => $this->center->id, 'project_id' => null, 'date' => Carbon::parse('2028-06-15'), 'currency' => 'EUR', 'amount' => '13000.00', 'consumed' => '0.00',
    ]);
    expect(BudgetOverride::query()->find($override['id'])->status)->toBe('consumed');

    $history = $this->getJson("/api/v1/budgeting/spend-control/overrides/{$override['id']}/history")->assertOk()->json('data');
    expect(array_column($history, 'action'))->toBe(['request', 'approve', 'consume']);
    $this->getJson('/api/v1/budgeting/spend-control/checks?outcome=overridden')->assertOk()->assertJsonCount(1, 'data');
});

test('four eyes: nobody decides their own override and a rejection needs a reason', function (): void {
    $override = $this->postJson('/api/v1/budgeting/spend-control/overrides', ['scope' => 'allowance', 'cost_center_id' => $this->center->id, 'requested_amount' => '500', 'reason' => 'Trade fair stand'])->assertCreated()->json('data');

    $this->postJson("/api/v1/budgeting/spend-control/overrides/{$override['id']}/approve")->assertUnprocessable()->assertJsonValidationErrors('status');

    $cfo = ctlMember($this->tenant, 'admin');
    ctlAct($cfo, $this->tenant);
    $this->postJson("/api/v1/budgeting/spend-control/overrides/{$override['id']}/reject")->assertUnprocessable()->assertJsonValidationErrors('comment');
    $this->postJson("/api/v1/budgeting/spend-control/overrides/{$override['id']}/reject", ['comment' => 'Not in the plan'])->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.decision_comment', 'Not in the plan');
});

test('an approved amount below the shortfall or an expired override does not lift the block', function (): void {
    ctlRule($this->tenant, 'hard_block');
    $service = app(SpendControlService::class);
    $documentId = (string) Str::uuid();
    $request = ctlRequest($this->tenant->id, $this->center->id, '13000.00', 'submit', 'purchase_order', $documentId);

    $override = BudgetOverride::create(['tenant_id' => $this->tenant->id, 'scope' => 'document', 'source_type' => 'purchase_order', 'source_id' => $documentId, 'fiscal_year' => 2028, 'requested_amount' => '13000.00', 'shortfall_amount' => '1000.00', 'approved_amount' => '600.00', 'reason' => 'Partial', 'status' => 'approved', 'valid_until' => now()->addDay()->toDateString()]);
    expect($service->evaluate($request, false)->outcome)->toBe(SpendDecision::Blocked);

    $override->update(['approved_amount' => '1000.00', 'valid_until' => now()->subDay()->toDateString()]);
    expect($service->evaluate($request, false)->outcome)->toBe(SpendDecision::Blocked);

    $override->update(['valid_until' => now()->toDateString()]);
    expect($service->evaluate($request, false)->outcome)->toBe(SpendDecision::Overridden);
});

test('a temporary allowance covers several documents until it is used up', function (): void {
    ctlRule($this->tenant, 'hard_block');
    ctlPost($this->tenant, $this->expense, '2028-02-10', '11500.00', $this->center);
    $allowance = BudgetOverride::create(['tenant_id' => $this->tenant->id, 'scope' => 'allowance', 'cost_center_id' => $this->center->id, 'fiscal_year' => 2028, 'requested_amount' => '800.00', 'shortfall_amount' => '800.00', 'approved_amount' => '600.00', 'reason' => 'Season peak', 'status' => 'approved', 'valid_until' => now()->addWeek()->toDateString()]);
    $service = app(SpendControlService::class);

    // 11,500 spent: 1,000 asked is 500 over the budget, inside the 600 allowance.
    $request = ctlRequest($this->tenant->id, $this->center->id, '1000.00');
    expect($service->evaluate($request, false)->outcome)->toBe(SpendDecision::Overridden)
        ->and($service->evaluate(ctlRequest($this->tenant->id, $this->center->id, '1200.00'), false)->outcome)->toBe(SpendDecision::Blocked);

    app(CommitmentService::class)->sync([
        'tenant_id' => $this->tenant->id, 'source_type' => 'purchase_order', 'source_id' => $request->sourceId, 'source_number' => 'PO-5', 'parent_source_id' => null, 'account_id' => null,
        'cost_center_id' => $this->center->id, 'project_id' => null, 'date' => Carbon::parse('2028-06-15'), 'currency' => 'EUR', 'amount' => '1000.00', 'consumed' => '0.00',
    ]);

    $allowance->refresh();
    expect($allowance->consumed_amount)->toBe('500.00')->and($allowance->status)->toBe('approved')->and($allowance->remaining())->toBe('100.00');
});

test('only authorised roles manage rules and thresholds, and the rules are validated', function (): void {
    $member = ctlMember($this->tenant, 'member');
    $accountant = ctlMember($this->tenant, 'accountant');
    $payload = ['name' => 'Centre lock', 'mode' => 'hard_block', 'threshold_percent' => 90, 'document_types' => ['purchase_order'], 'stages' => ['submit', 'approve'], 'cost_center_id' => $this->center->id];

    ctlAct($member, $this->tenant);
    $this->postJson('/api/v1/budgeting/spend-control/rules', $payload)->assertForbidden();
    $this->putJson('/api/v1/budgeting/spend-control/settings', ['amber_threshold_percent' => 3])->assertForbidden();
    $this->getJson('/api/v1/budgeting/spend-control/rules')->assertOk();

    ctlAct($accountant, $this->tenant);
    $rule = $this->postJson('/api/v1/budgeting/spend-control/rules', $payload)->assertCreated()->json('data');
    expect($rule['mode'])->toBe('hard_block')->and($rule['threshold_percent'])->toBe('90.00')->and($rule['is_active'])->toBeTrue();
    $this->postJson('/api/v1/budgeting/spend-control/rules', [...$payload, 'mode' => 'nope', 'stages' => ['never'], 'document_types' => []])->assertUnprocessable()->assertJsonValidationErrors(['mode', 'stages.0', 'document_types']);
    $this->postJson('/api/v1/budgeting/spend-control/rules', [...$payload, 'cost_center_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('cost_center_id');
    $this->putJson("/api/v1/budgeting/spend-control/rules/{$rule['id']}", [...$payload, 'mode' => 'warning'])->assertOk()->assertJsonPath('data.mode', 'warning');
    $this->putJson('/api/v1/budgeting/spend-control/settings', ['amber_threshold_percent' => 20, 'red_threshold_percent' => 10])->assertUnprocessable()->assertJsonValidationErrors('amber_threshold_percent');
    $this->putJson('/api/v1/budgeting/spend-control/settings', ['amber_threshold_percent' => 3, 'red_threshold_percent' => 12, 'override_validity_days' => 10])->assertOk()->assertJsonPath('data.red_threshold_percent', '12.00');
    $this->deleteJson("/api/v1/budgeting/spend-control/rules/{$rule['id']}")->assertNoContent();
    expect(BudgetSpendRule::query()->count())->toBe(0);
});

test('a member granted the permission decides overrides and another company never sees them', function (): void {
    $override = $this->postJson('/api/v1/budgeting/spend-control/overrides', ['scope' => 'allowance', 'cost_center_id' => $this->center->id, 'requested_amount' => '500', 'reason' => 'Trade fair stand'])->assertCreated()->json('data');

    $granted = ctlMember($this->tenant, 'member', ['budgeting.override.approve']);
    ctlAct($granted, $this->tenant);
    $this->postJson("/api/v1/budgeting/spend-control/overrides/{$override['id']}/approve", ['approved_amount' => 300])->assertOk()->assertJsonPath('data.approved_amount', '300.00');

    $foreign = ctlTenant('Foreign');
    ctlAct(ctlMember($foreign, 'owner'), $foreign);
    $this->getJson("/api/v1/budgeting/spend-control/overrides/{$override['id']}")->assertNotFound();
    $this->getJson('/api/v1/budgeting/spend-control/overrides')->assertOk()->assertJsonCount(0, 'data');
});

test('the requester or the CFO cancels an open override and a decided one cannot be cancelled', function (): void {
    $buyer = ctlMember($this->tenant, 'member');
    $colleague = ctlMember($this->tenant, 'member');
    ctlAct($buyer, $this->tenant);
    $first = $this->postJson('/api/v1/budgeting/spend-control/overrides', ['scope' => 'allowance', 'cost_center_id' => $this->center->id, 'requested_amount' => '500', 'reason' => 'Trade fair stand'])->assertCreated()->json('data');
    $second = $this->postJson('/api/v1/budgeting/spend-control/overrides', ['scope' => 'allowance', 'cost_center_id' => $this->center->id, 'requested_amount' => '700', 'reason' => 'Season peak'])->assertCreated()->json('data');

    ctlAct($colleague, $this->tenant);
    $this->postJson("/api/v1/budgeting/spend-control/overrides/{$first['id']}/cancel")->assertForbidden();

    ctlAct($buyer, $this->tenant);
    $this->postJson("/api/v1/budgeting/spend-control/overrides/{$first['id']}/cancel", ['comment' => 'Not needed any more'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->postJson("/api/v1/budgeting/spend-control/overrides/{$first['id']}/cancel")->assertUnprocessable()->assertJsonValidationErrors('status');

    ctlAct($this->owner, $this->tenant);
    $this->postJson("/api/v1/budgeting/spend-control/overrides/{$second['id']}/approve")->assertOk();
    $this->postJson("/api/v1/budgeting/spend-control/overrides/{$second['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->getJson('/api/v1/budgeting/spend-control/overrides?status=cancelled')->assertOk()->assertJsonCount(2, 'data');
});
