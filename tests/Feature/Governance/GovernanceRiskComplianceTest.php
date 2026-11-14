<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Documents\Models\DmsDocument;
use Modules\Governance\Models\GovRisk;
use Modules\Governance\Services\ComplianceTemplates;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2027-06-22 09:00:00');
    $this->tenant = grcTenant('Governance Rossi');
    $this->owner = grcMember($this->tenant, 'owner');
    $this->admin = grcMember($this->tenant, 'admin');
    $this->accountant = grcMember($this->tenant, 'accountant');
    $this->member = grcMember($this->tenant, 'member');
    grcAct($this->owner, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

function grcTenant(string $name): Tenant
{
    return Tenant::create(['name' => $name.' '.Str::random(4), 'legal_name' => $name.' Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
}

function grcMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@grc-test.example', 'password' => bcrypt(Str::random(32)), 'locale' => 'it']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function grcAct(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->setTenant($tenant);
    Sanctum::actingAs($user);
    test()->withHeaders(['X-Tenant-Id' => (string) $tenant->id]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function grcRisk(array $overrides = []): array
{
    return test()->postJson('/api/v1/governance/risks', [
        'title' => 'Ransomware on the file servers',
        'category' => 'it_security',
        'likelihood' => 3,
        'impact' => 4,
        ...$overrides,
    ])->assertCreated()->json('data');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function grcControl(array $overrides = []): array
{
    return test()->postJson('/api/v1/governance/controls', [
        'title' => 'Offline backup restore test',
        'control_type' => 'detective',
        'frequency' => 'monthly',
        'status' => 'active',
        ...$overrides,
    ])->assertCreated()->json('data');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function grcFinding(array $overrides = []): array
{
    return test()->postJson('/api/v1/governance/findings', [
        'source' => 'self_assessment',
        'title' => 'Privileged accounts without periodic review',
        'severity' => 'high',
        ...$overrides,
    ])->assertCreated()->json('data');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function grcAction(string $findingId, string $ownerId, array $overrides = []): array
{
    return test()->postJson("/api/v1/governance/findings/{$findingId}/actions", [
        'title' => 'Review the privileged accounts',
        'owner_id' => $ownerId,
        'due_date' => '2027-08-01',
        ...$overrides,
    ])->assertCreated()->json('data');
}

function grcTransition(string $resource, string $id, string $status, ?string $notes = null): TestResponse
{
    return test()->postJson("/api/v1/governance/{$resource}/{$id}/transition", array_filter(['status' => $status, 'notes' => $notes], static fn (?string $value): bool => $value !== null));
}

function grcDocument(Tenant $tenant): DmsDocument
{
    return app(TenantContext::class)->runWithTenant($tenant, fn (): DmsDocument => DmsDocument::create(['tenant_id' => $tenant->id, 'title' => 'Restore test report', 'status' => 'draft', 'current_version' => 1, 'access_roles' => [], 'metadata' => [], 'search_text' => '', 'legal_hold' => false]));
}

test('risks are scored likelihood x impact, rated and given a review date by rating', function (): void {
    $risk = grcRisk(['likelihood' => 4, 'impact' => 5]);

    expect($risk['code'])->toBe('RSK-2027-0001')
        ->and($risk['inherent_score'])->toBe(20)
        ->and($risk['inherent_rating'])->toBe('critical')
        ->and($risk['residual_score'])->toBeNull()
        ->and($risk['status'])->toBe('identified')
        ->and($risk['next_review_date'])->toBe('2027-09-22');

    $low = grcRisk(['title' => 'Late supplier invoice', 'category' => 'financial', 'likelihood' => 2, 'impact' => 2]);
    expect($low['code'])->toBe('RSK-2027-0002')
        ->and($low['inherent_rating'])->toBe('low')
        ->and($low['next_review_date'])->toBe('2028-06-22');

    $this->postJson('/api/v1/governance/risks', ['title' => 'x', 'category' => 'it_security', 'likelihood' => 6, 'impact' => 1])->assertUnprocessable();
    $this->postJson('/api/v1/governance/risks', ['title' => 'x', 'category' => 'unknown', 'likelihood' => 1, 'impact' => 1])->assertUnprocessable();
    $this->postJson('/api/v1/governance/risks', ['title' => 'x', 'category' => 'legal', 'likelihood' => 3, 'impact' => 3, 'residual_impact' => 2])->assertUnprocessable();
    $this->postJson('/api/v1/governance/risks', ['title' => 'x', 'category' => 'legal', 'likelihood' => 3, 'impact' => 3, 'residual_likelihood' => 4, 'residual_impact' => 2])->assertUnprocessable();

    $updated = $this->putJson("/api/v1/governance/risks/{$risk['id']}", ['residual_likelihood' => 2, 'residual_impact' => 3])->assertOk()->json('data');
    expect($updated['residual_score'])->toBe(6)->and($updated['residual_rating'])->toBe('medium');

    // lowering the inherent score below the residual one is refused
    $this->putJson("/api/v1/governance/risks/{$risk['id']}", ['likelihood' => 1])->assertUnprocessable();

    expect($this->getJson('/api/v1/governance/risks?rating=critical')->assertOk()->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/v1/governance/risks?rating=low')->assertOk()->json('data.0.code'))->toBe('RSK-2027-0002')
        ->and($this->getJson('/api/v1/governance/risks?category=financial')->assertOk()->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/v1/governance/risks?search=Ransom')->assertOk()->json('data'))->toHaveCount(1);
});

test('a risk follows its state machine: owner to assess, active control to treat, authority to accept', function (): void {
    $risk = grcRisk();

    grcTransition('risks', $risk['id'], 'treating')->assertUnprocessable();
    grcTransition('risks', $risk['id'], 'unknown')->assertUnprocessable();
    grcTransition('risks', $risk['id'], 'assessed')->assertUnprocessable()->assertJsonValidationErrors('owner_id');

    $this->putJson("/api/v1/governance/risks/{$risk['id']}", ['owner_id' => $this->member->id])->assertOk();
    grcTransition('risks', $risk['id'], 'assessed')->assertOk()->assertJsonPath('data.status', 'assessed');

    // treatment needs an active control that mitigates the risk
    grcTransition('risks', $risk['id'], 'treating')->assertUnprocessable();
    $draft = grcControl(['status' => 'draft']);
    $this->putJson("/api/v1/governance/risks/{$risk['id']}/controls", ['control_ids' => [$draft['id']]])->assertOk()->assertJsonCount(1, 'data.controls');
    grcTransition('risks', $risk['id'], 'treating')->assertUnprocessable();

    $this->putJson("/api/v1/governance/controls/{$draft['id']}", ['status' => 'active'])->assertOk()->assertJsonPath('data.next_test_due_date', '2027-12-22');
    grcTransition('risks', $risk['id'], 'treating')->assertOk()->assertJsonPath('data.treatment', 'mitigate');

    // accepting a risk is a management decision: accountants keep the register but cannot accept
    grcAct($this->accountant, $this->tenant);
    grcTransition('risks', $risk['id'], 'accepted', 'Residual risk tolerated by the board')->assertForbidden();

    grcAct($this->owner, $this->tenant);
    grcTransition('risks', $risk['id'], 'accepted')->assertUnprocessable();
    $accepted = grcTransition('risks', $risk['id'], 'accepted', 'Residual risk tolerated by the board')->assertOk()->json('data');
    expect($accepted['status'])->toBe('accepted')
        ->and($accepted['treatment'])->toBe('accept')
        ->and($accepted['residual_score'])->toBe(12);

    grcTransition('risks', $risk['id'], 'closed')->assertOk()->assertJsonPath('data.status', 'closed');
    $this->putJson("/api/v1/governance/risks/{$risk['id']}", ['title' => 'Changed'])->assertUnprocessable();
    grcTransition('risks', $risk['id'], 'assessed')->assertOk()->assertJsonPath('data.closed_at', null);

    $this->putJson("/api/v1/governance/risks/{$risk['id']}/controls", ['control_ids' => []])->assertOk()->assertJsonCount(0, 'data.controls');
    $this->putJson("/api/v1/governance/risks/{$risk['id']}/controls", ['control_ids' => [(string) Str::uuid7()]])->assertUnprocessable();

    // a risk with an open finding cannot be deleted
    $finding = grcFinding(['risk_id' => $risk['id']]);
    $this->deleteJson("/api/v1/governance/risks/{$risk['id']}")->assertUnprocessable();
    grcTransition('findings', $finding['id'], 'risk_accepted', 'Tolerated')->assertOk();
    $this->deleteJson("/api/v1/governance/risks/{$risk['id']}")->assertNoContent();
    $this->getJson("/api/v1/governance/risks/{$risk['id']}")->assertNotFound();
});

test('control tests schedule the next test, keep evidence and can raise a finding', function (): void {
    $control = grcControl();
    expect($control['code'])->toBe('CTL-2027-0001')
        ->and($control['next_test_due_date'])->toBe('2027-12-22')
        ->and($control['last_tested_at'])->toBeNull();

    $draft = grcControl(['status' => 'draft', 'title' => 'Draft control']);
    $this->postJson("/api/v1/governance/controls/{$draft['id']}/tests", ['tested_on' => '2027-06-20', 'result' => 'pass'])->assertUnprocessable();

    $payload = ['tested_on' => '2027-06-20', 'result' => 'pass', 'sample_size' => 20, 'exceptions_found' => 0];
    $this->postJson("/api/v1/governance/controls/{$control['id']}/tests", [...$payload, 'tested_on' => '2027-06-23'])->assertUnprocessable();
    $this->postJson("/api/v1/governance/controls/{$control['id']}/tests", [...$payload, 'exceptions_found' => 25])->assertUnprocessable();
    $this->postJson("/api/v1/governance/controls/{$control['id']}/tests", [...$payload, 'evidence_document_ids' => [(string) Str::uuid7()]])->assertUnprocessable();

    $document = grcDocument($this->tenant);
    $run = $this->postJson("/api/v1/governance/controls/{$control['id']}/tests", [...$payload, 'evidence_document_ids' => [$document->id]])->assertCreated()->json('data');
    expect($run['evidence_document_ids'])->toBe([$document->id]);

    $afterPass = $this->getJson("/api/v1/governance/controls/{$control['id']}")->assertOk()->json('data');
    expect($afterPass['last_tested_at'])->toBe('2027-06-20')
        ->and($afterPass['last_test_result'])->toBe('pass')
        ->and($afterPass['next_test_due_date'])->toBe('2027-12-20');

    $failed = $this->postJson("/api/v1/governance/controls/{$control['id']}/tests", ['tested_on' => '2027-06-21', 'result' => 'fail', 'notes' => 'Restore failed for two volumes', 'raise_finding' => true])->assertCreated()->json('data');
    expect($failed['finding_id'])->not->toBeNull();

    $afterFail = $this->getJson("/api/v1/governance/controls/{$control['id']}")->assertOk()->json('data');
    expect($afterFail['last_test_result'])->toBe('fail')->and($afterFail['next_test_due_date'])->toBe('2027-07-21');

    $finding = $this->getJson("/api/v1/governance/findings/{$failed['finding_id']}")->assertOk()->json('data');
    expect($finding['source'])->toBe('control_test')
        ->and($finding['control_id'])->toBe($control['id'])
        ->and($finding['severity'])->toBe('high');

    // an older test is recorded but does not move the schedule
    $this->postJson("/api/v1/governance/controls/{$control['id']}/tests", ['tested_on' => '2027-01-10', 'result' => 'pass'])->assertCreated();
    expect($this->getJson("/api/v1/governance/controls/{$control['id']}")->json('data.last_tested_at'))->toBe('2027-06-21')
        ->and($this->getJson("/api/v1/governance/controls/{$control['id']}/tests")->assertOk()->json('data'))->toHaveCount(3);
});

test('a control that is not tested in time is flagged overdue', function (): void {
    $control = grcControl(['frequency' => 'weekly']);
    expect($control['is_test_overdue'])->toBeFalse();

    $this->travelTo('2027-10-01 09:00:00');

    expect($this->getJson("/api/v1/governance/controls/{$control['id']}")->json('data.is_test_overdue'))->toBeTrue()
        ->and($this->getJson('/api/v1/governance/controls?test_overdue=1')->assertOk()->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/v1/governance/dashboard')->json('data.controls.test_overdue'))->toBe(1);
});

test('the audit plan, its audits and the fieldwork follow their rules', function (): void {
    $plan = $this->postJson('/api/v1/governance/audit-plans', ['year' => 2027, 'title' => 'Internal audit plan 2027'])->assertCreated()->json('data');
    expect($plan['status'])->toBe('draft');
    $this->postJson('/api/v1/governance/audit-plans', ['year' => 2027, 'title' => 'Duplicate'])->assertUnprocessable();

    $audit = [
        'audit_plan_id' => $plan['id'],
        'title' => 'Access management audit',
        'audit_type' => 'it',
        'scope' => 'User provisioning and privileged access',
        'lead_auditor_id' => $this->admin->id,
        'team_member_ids' => [$this->admin->id, $this->accountant->id],
        'planned_start' => '2027-09-01',
        'planned_end' => '2027-09-15',
    ];

    $this->postJson('/api/v1/governance/audits', [...$audit, 'planned_start' => '2028-01-10', 'planned_end' => '2028-01-20'])->assertUnprocessable();
    $this->postJson('/api/v1/governance/audits', [...$audit, 'planned_end' => '2027-08-15'])->assertUnprocessable();
    $this->postJson('/api/v1/governance/audits', [...$audit, 'lead_auditor_id' => (string) Str::uuid7()])->assertUnprocessable();

    grcTransition('audit-plans', $plan['id'], 'approved')->assertUnprocessable();

    $created = $this->postJson('/api/v1/governance/audits', $audit)->assertCreated()->json('data');
    expect($created['code'])->toBe('AUD-2027-0001')
        ->and($created['team_member_ids'])->toBe([$this->accountant->id])
        ->and($created['status'])->toBe('planned');

    // a finding is raised during fieldwork only, and the audit starts under an approved plan
    $finding = ['source' => 'audit', 'audit_id' => $created['id'], 'title' => 'Leavers keep access', 'severity' => 'high'];
    $this->postJson('/api/v1/governance/findings', $finding)->assertUnprocessable();
    grcTransition('audits', $created['id'], 'in_progress')->assertUnprocessable();

    grcAct($this->accountant, $this->tenant);
    grcTransition('audit-plans', $plan['id'], 'approved')->assertForbidden();
    grcAct($this->owner, $this->tenant);
    grcTransition('audit-plans', $plan['id'], 'approved')->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approved_by.id', $this->owner->id);

    grcTransition('audits', $created['id'], 'in_progress')->assertOk()->assertJsonPath('data.actual_start', '2027-06-22');
    $this->putJson("/api/v1/governance/audits/{$created['id']}", ['scope' => 'Changed scope'])->assertUnprocessable();
    $this->putJson("/api/v1/governance/audits/{$created['id']}", ['conclusion' => 'Draft conclusion'])->assertOk();

    $raised = $this->postJson('/api/v1/governance/findings', $finding)->assertCreated()->json('data');
    grcTransition('audits', $created['id'], 'reporting')->assertOk();
    grcTransition('audits', $created['id'], 'closed')->assertUnprocessable()->assertJsonValidationErrors('status');

    // the high finding must have a remediation plan before the audit closes
    grcAction($raised['id'], $this->member->id);
    grcTransition('audits', $created['id'], 'closed')->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.actual_end', '2027-06-22');
    grcTransition('audits', $created['id'], 'in_progress')->assertUnprocessable();
    $this->putJson("/api/v1/governance/audits/{$created['id']}", ['conclusion' => 'x'])->assertUnprocessable();

    grcTransition('audit-plans', $plan['id'], 'closed')->assertOk()->assertJsonPath('data.status', 'closed');
    $this->postJson('/api/v1/governance/audits', [...$audit, 'title' => 'Late audit'])->assertUnprocessable();
    expect($this->getJson("/api/v1/governance/audit-plans/{$plan['id']}")->assertOk()->json('data.audits'))->toHaveCount(1);
});

test('an audit plan cannot close while an audit is unfinished and a cancelled audit needs a reason', function (): void {
    $plan = $this->postJson('/api/v1/governance/audit-plans', ['year' => 2027, 'title' => 'Plan'])->assertCreated()->json('data');
    $audit = $this->postJson('/api/v1/governance/audits', [
        'audit_plan_id' => $plan['id'], 'title' => 'Procurement audit', 'audit_type' => 'process', 'scope' => 'Purchase to pay',
        'lead_auditor_id' => $this->admin->id, 'planned_start' => '2027-05-01', 'planned_end' => '2027-05-10',
    ])->assertCreated()->json('data');

    grcTransition('audit-plans', $plan['id'], 'approved')->assertOk();
    expect($audit['is_late_to_start'])->toBeTrue()
        ->and($this->getJson('/api/v1/governance/audits?late=1')->assertOk()->json('data'))->toHaveCount(1);

    grcTransition('audit-plans', $plan['id'], 'closed')->assertUnprocessable();
    grcTransition('audits', $audit['id'], 'cancelled')->assertUnprocessable();
    grcTransition('audits', $audit['id'], 'cancelled', 'Merged into the IT audit')->assertOk()->assertJsonPath('data.conclusion', 'Merged into the IT audit');
    grcTransition('audit-plans', $plan['id'], 'closed')->assertOk();
});

test('remediation actions respect the severity term, are verified by somebody else and resolve the finding', function (): void {
    $finding = grcFinding(['severity' => 'high']);
    expect($finding['code'])->toBe('FND-2027-0001')->and($finding['status'])->toBe('open');

    // high findings give 90 days (until 2027-09-20), never a past date
    $this->postJson("/api/v1/governance/findings/{$finding['id']}/actions", ['title' => 'Too late', 'owner_id' => $this->member->id, 'due_date' => '2027-12-31'])->assertUnprocessable()->assertJsonValidationErrors('due_date');
    $this->postJson("/api/v1/governance/findings/{$finding['id']}/actions", ['title' => 'Past', 'owner_id' => $this->member->id, 'due_date' => '2027-06-01'])->assertUnprocessable();
    $this->postJson("/api/v1/governance/findings/{$finding['id']}/actions", ['title' => 'Stranger', 'owner_id' => (string) Str::uuid7(), 'due_date' => '2027-08-01'])->assertUnprocessable();
    grcTransition('findings', $finding['id'], 'remediation')->assertUnprocessable();
    grcTransition('findings', $finding['id'], 'resolved')->assertUnprocessable();

    $memberAction = grcAction($finding['id'], $this->member->id);
    $adminAction = grcAction($finding['id'], $this->admin->id, ['title' => 'Automate the review']);
    expect($memberAction['code'])->toBe('ACT-2027-0001')
        ->and($this->getJson("/api/v1/governance/findings/{$finding['id']}")->json('data.status'))->toBe('remediation')
        ->and($this->getJson("/api/v1/governance/findings/{$finding['id']}")->json('data.actions'))->toHaveCount(2);

    // the owner of an action works on it even as an ordinary member, but cannot verify
    grcAct($this->member, $this->tenant);
    grcTransition('actions', $memberAction['id'], 'in_progress')->assertOk()->assertJsonPath('data.status', 'in_progress');
    grcTransition('actions', $adminAction['id'], 'in_progress')->assertForbidden();
    $this->putJson("/api/v1/governance/actions/{$memberAction['id']}", ['title' => 'Changed'])->assertForbidden();
    grcTransition('actions', $memberAction['id'], 'completed', 'Reviewed 14 accounts')->assertOk()->assertJsonPath('data.completed_by.id', $this->member->id);
    grcTransition('actions', $memberAction['id'], 'verified')->assertForbidden();
    $this->getJson('/api/v1/governance/actions?mine=1')->assertOk()->assertJsonCount(1, 'data');

    // somebody else verifies; sending back needs a reason
    grcAct($this->owner, $this->tenant);
    $this->putJson("/api/v1/governance/actions/{$memberAction['id']}", ['title' => 'Changed'])->assertUnprocessable();
    grcTransition('actions', $memberAction['id'], 'in_progress')->assertUnprocessable();
    grcTransition('actions', $memberAction['id'], 'in_progress', 'Evidence is missing')->assertOk();
    grcAct($this->member, $this->tenant);
    grcTransition('actions', $memberAction['id'], 'completed', 'Evidence attached')->assertOk();
    grcAct($this->owner, $this->tenant);
    grcTransition('actions', $memberAction['id'], 'verified', 'Checked the evidence')->assertOk()->assertJsonPath('data.verified_by.id', $this->owner->id);
    grcTransition('actions', $memberAction['id'], 'cancelled', 'x')->assertUnprocessable();

    // segregation of duties: the administrator who completed an action cannot verify it
    grcAct($this->admin, $this->tenant);
    grcTransition('actions', $adminAction['id'], 'completed')->assertOk();
    grcTransition('actions', $adminAction['id'], 'verified')->assertUnprocessable();
    expect($this->getJson("/api/v1/governance/findings/{$finding['id']}")->json('data.status'))->toBe('remediation');

    grcAct($this->owner, $this->tenant);
    grcTransition('actions', $adminAction['id'], 'verified')->assertOk();
    expect($this->getJson("/api/v1/governance/findings/{$finding['id']}")->json('data.status'))->toBe('resolved');

    // closing a high finding is a management decision and needs notes
    grcAct($this->accountant, $this->tenant);
    grcTransition('findings', $finding['id'], 'closed', 'All accounts reviewed')->assertForbidden();
    grcAct($this->owner, $this->tenant);
    grcTransition('findings', $finding['id'], 'closed')->assertUnprocessable();
    grcTransition('findings', $finding['id'], 'closed', 'All accounts reviewed')->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.closed_by.id', $this->owner->id);

    $this->postJson("/api/v1/governance/findings/{$finding['id']}/actions", ['title' => 'Late', 'owner_id' => $this->member->id, 'due_date' => '2027-08-01'])->assertUnprocessable();
    grcTransition('findings', $finding['id'], 'open')->assertOk()->assertJsonPath('data.closed_at', null);
});

test('a new action reopens the remediation of a resolved finding and a rejected one holds it back', function (): void {
    $finding = grcFinding(['severity' => 'medium']);
    $action = grcAction($finding['id'], $this->member->id, ['due_date' => '2027-12-01']);

    grcAct($this->member, $this->tenant);
    grcTransition('actions', $action['id'], 'completed')->assertOk();
    grcAct($this->owner, $this->tenant);
    grcTransition('actions', $action['id'], 'verified')->assertOk();
    expect($this->getJson("/api/v1/governance/findings/{$finding['id']}")->json('data.status'))->toBe('resolved');

    $second = grcAction($finding['id'], $this->member->id, ['title' => 'Second action', 'due_date' => '2027-12-01']);
    expect($this->getJson("/api/v1/governance/findings/{$finding['id']}")->json('data.status'))->toBe('remediation');

    // cancelling the only open action while another is verified resolves it again
    grcTransition('actions', $second['id'], 'cancelled', 'Not needed')->assertOk();
    expect($this->getJson("/api/v1/governance/findings/{$finding['id']}")->json('data.status'))->toBe('resolved');

    // a medium finding closes without the approval authority
    grcAct($this->accountant, $this->tenant);
    grcTransition('findings', $finding['id'], 'closed', 'Done')->assertOk();
});

test('accepting the risk of a finding needs authority and a reason and cancels the live actions', function (): void {
    $finding = grcFinding(['severity' => 'low']);
    $action = grcAction($finding['id'], $this->member->id, ['due_date' => '2027-12-01']);

    grcAct($this->accountant, $this->tenant);
    grcTransition('findings', $finding['id'], 'risk_accepted', 'Cost exceeds the benefit')->assertForbidden();

    grcAct($this->owner, $this->tenant);
    grcTransition('findings', $finding['id'], 'risk_accepted')->assertUnprocessable();
    $accepted = grcTransition('findings', $finding['id'], 'risk_accepted', 'Cost exceeds the benefit')->assertOk()->json('data');

    expect($accepted['status'])->toBe('risk_accepted')
        ->and($accepted['accepted_reason'])->toBe('Cost exceeds the benefit')
        ->and($accepted['accepted_by']['id'])->toBe($this->owner->id)
        ->and($this->getJson("/api/v1/governance/actions/{$action['id']}")->json('data.status'))->toBe('cancelled');
});

test('overdue actions and obligations are detected', function (): void {
    $finding = grcFinding(['severity' => 'critical']);
    grcAction($finding['id'], $this->member->id, ['due_date' => '2027-07-10']);
    $this->postJson('/api/v1/governance/obligations', ['framework' => 'gdpr', 'jurisdiction' => 'EU', 'title' => 'Review the register', 'recurrence' => 'annual', 'due_date' => '2027-07-05'])->assertCreated();
    $this->postJson('/api/v1/governance/obligations', ['framework' => 'other', 'jurisdiction' => 'IT', 'title' => 'Chamber of commerce filing', 'recurrence' => 'once', 'due_date' => '2027-12-05'])->assertCreated();

    expect($this->getJson('/api/v1/governance/actions?overdue=1')->assertOk()->json('data'))->toHaveCount(0)
        ->and($this->getJson('/api/v1/governance/dashboard')->json('data.actions.overdue'))->toBe(0)
        ->and($this->getJson('/api/v1/governance/obligations?due_within_days=30')->json('data'))->toHaveCount(1);

    $this->travelTo('2027-07-20 09:00:00');

    $actions = $this->getJson('/api/v1/governance/actions?overdue=1')->assertOk()->json('data');
    expect($actions)->toHaveCount(1)
        ->and($actions[0]['is_overdue'])->toBeTrue()
        ->and($actions[0]['finding']['severity'])->toBe('critical')
        ->and($this->getJson('/api/v1/governance/findings?overdue=1')->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/v1/governance/obligations?overdue=1')->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/v1/governance/obligations?overdue=1')->json('data.0.is_overdue'))->toBeTrue();

    $dashboard = $this->getJson('/api/v1/governance/dashboard')->assertOk()->json('data');
    expect($dashboard['actions']['overdue'])->toBe(1)
        ->and($dashboard['obligations']['overdue'])->toBe(1)
        ->and($dashboard['findings']['open_by_severity']['critical'])->toBe(1);
});

test('compliance templates are seeded once per framework and keep the calendar current', function (): void {
    $this->postJson('/api/v1/governance/obligations/seed-templates', ['frameworks' => ['other']])->assertUnprocessable();
    $this->postJson('/api/v1/governance/obligations/seed-templates', ['frameworks' => []])->assertUnprocessable();
    $this->postJson('/api/v1/governance/obligations/seed-templates', ['frameworks' => ['gdpr'], 'first_due_date' => '2027-01-01'])->assertUnprocessable();

    $expected = count(ComplianceTemplates::forFrameworks(['d_lgs_231', 'gdpr', 'nis2', 'fadp', 'iso_27001']));
    expect($expected)->toBeGreaterThan(20);

    $first = $this->postJson('/api/v1/governance/obligations/seed-templates', ['frameworks' => ['d_lgs_231', 'gdpr', 'nis2', 'fadp', 'iso_27001'], 'first_due_date' => '2027-07-15', 'owner_id' => $this->accountant->id])->assertOk()->json('data');
    expect($first)->toBe(['created' => $expected, 'skipped' => 0]);
    expect($this->postJson('/api/v1/governance/obligations/seed-templates', ['frameworks' => ['gdpr']])->assertOk()->json('data'))->toBe(['created' => 0, 'skipped' => count(ComplianceTemplates::forFrameworks(['gdpr']))]);

    $fadp = $this->getJson('/api/v1/governance/obligations?framework=fadp&per_page=100')->assertOk()->json('data');
    expect($fadp)->not->toBeEmpty()
        ->and(collect($fadp)->pluck('jurisdiction')->unique()->all())->toBe(['CH'])
        ->and($fadp[0]['owner']['id'])->toBe($this->accountant->id)
        ->and($fadp[0]['due_date'])->toBe('2027-07-15')
        ->and($fadp[0]['is_due_soon'])->toBeTrue();

    $templates = $this->getJson('/api/v1/governance/obligations/templates')->assertOk()->json('data');
    expect($templates)->toHaveCount($expected)->and(collect($templates)->every(fn (array $template): bool => $template['already_seeded']))->toBeTrue();
});

test('completing an obligation moves a recurring one to its next period and closes a one-off', function (): void {
    $quarterly = $this->postJson('/api/v1/governance/obligations', ['framework' => 'gdpr', 'jurisdiction' => 'EU', 'title' => 'Data subject requests review', 'recurrence' => 'quarterly', 'due_date' => '2027-07-15', 'owner_id' => $this->member->id])->assertCreated()->json('data');
    $late = $this->postJson('/api/v1/governance/obligations', ['framework' => 'iso_27001', 'jurisdiction' => 'INT', 'title' => 'Access review', 'recurrence' => 'monthly', 'due_date' => '2027-03-10'])->assertCreated()->json('data');
    $once = $this->postJson('/api/v1/governance/obligations', ['framework' => 'fadp', 'jurisdiction' => 'CH', 'title' => 'Register with the FDPIC', 'recurrence' => 'once', 'due_date' => '2027-06-30'])->assertCreated()->json('data');
    $document = grcDocument($this->tenant);

    $this->postJson("/api/v1/governance/obligations/{$once['id']}/complete", ['evidence_document_id' => (string) Str::uuid7()])->assertUnprocessable();
    $this->postJson("/api/v1/governance/obligations/{$once['id']}/complete", ['completed_on' => '2027-06-23'])->assertUnprocessable();

    $next = $this->postJson("/api/v1/governance/obligations/{$quarterly['id']}/complete", ['completed_on' => '2027-06-22', 'evidence_document_id' => $document->id])->assertOk()->json('data');
    expect($next['status'])->toBe('pending')
        ->and($next['due_date'])->toBe('2027-10-15')
        ->and($next['last_completed_at'])->toBe('2027-06-22')
        ->and($next['evidence_document_id'])->toBe($document->id);

    // fulfilled very late: the periods already gone are skipped
    expect($this->postJson("/api/v1/governance/obligations/{$late['id']}/complete")->assertOk()->json('data.due_date'))->toBe('2027-07-10');

    $done = $this->postJson("/api/v1/governance/obligations/{$once['id']}/complete")->assertOk()->json('data');
    expect($done['status'])->toBe('completed');
    $this->postJson("/api/v1/governance/obligations/{$once['id']}/complete")->assertUnprocessable();
    $this->putJson("/api/v1/governance/obligations/{$once['id']}", ['title' => 'Changed'])->assertUnprocessable();
    grcTransition('obligations', $once['id'], 'pending')->assertUnprocessable();

    grcTransition('obligations', $quarterly['id'], 'in_progress')->assertOk();
    grcTransition('obligations', $quarterly['id'], 'cancelled')->assertOk();
    $this->postJson("/api/v1/governance/obligations/{$quarterly['id']}/complete")->assertUnprocessable();
    grcTransition('obligations', $quarterly['id'], 'pending')->assertOk();
});

test('the dashboard shows the heatmap, open findings, actions and the control test pass rate', function (): void {
    $critical = grcRisk(['likelihood' => 5, 'impact' => 5, 'residual_likelihood' => 2, 'residual_impact' => 2]);
    grcRisk(['title' => 'Key person dependency', 'category' => 'operational', 'likelihood' => 5, 'impact' => 5]);
    grcRisk(['title' => 'Minor FX exposure', 'category' => 'financial', 'likelihood' => 1, 'impact' => 2]);
    $closed = grcRisk(['title' => 'Closed risk', 'category' => 'legal', 'likelihood' => 3, 'impact' => 3]);
    grcTransition('risks', $closed['id'], 'closed')->assertOk();

    $control = grcControl();
    foreach ([['2027-05-01', 'pass'], ['2027-05-15', 'pass'], ['2027-06-01', 'fail']] as [$date, $result]) {
        $this->postJson("/api/v1/governance/controls/{$control['id']}/tests", ['tested_on' => $date, 'result' => $result])->assertCreated();
    }

    grcFinding(['severity' => 'critical']);
    $high = grcFinding(['severity' => 'high', 'title' => 'Second finding']);
    grcAction($high['id'], $this->member->id);

    $plan = $this->postJson('/api/v1/governance/audit-plans', ['year' => 2027, 'title' => 'Plan 2027'])->assertCreated()->json('data');

    $data = $this->getJson('/api/v1/governance/dashboard')->assertOk()->json('data');
    $cells = collect($data['risks']['heatmap'])->keyBy(fn (array $cell): string => "{$cell['likelihood']}-{$cell['impact']}");

    expect($data['risks']['total'])->toBe(3)
        ->and($data['risks']['heatmap'])->toHaveCount(25)
        ->and($cells['2-2']['count'])->toBe(1)
        ->and($cells['5-5']['count'])->toBe(1)
        ->and($cells['1-2']['count'])->toBe(1)
        ->and($cells['5-5']['rating'])->toBe('critical')
        ->and($data['risks']['by_rating'])->toBe(['low' => 2, 'medium' => 0, 'high' => 0, 'critical' => 1])
        ->and($data['risks']['by_status']['closed'])->toBe(1)
        ->and($data['controls']['active'])->toBe(1)
        ->and($data['controls']['tests_in_period'])->toBe(3)
        ->and($data['controls']['pass_rate'])->toBe(66.7)
        ->and($data['findings']['open_total'])->toBe(2)
        ->and($data['findings']['open_by_severity'])->toBe(['low' => 0, 'medium' => 0, 'high' => 1, 'critical' => 1])
        ->and($data['actions']['open'])->toBe(1)
        ->and($data['actions']['due_next_30_days'])->toBe(0)
        ->and($data['audits']['plan_status'])->toBe('draft')
        ->and($data['audits']['plan_completion_rate'])->toBeNull();

    $inherent = $this->getJson('/api/v1/governance/dashboard?basis=inherent')->assertOk()->json('data');
    $inherentCells = collect($inherent['risks']['heatmap'])->keyBy(fn (array $cell): string => "{$cell['likelihood']}-{$cell['impact']}");
    expect($inherent['basis'])->toBe('inherent')
        ->and($inherentCells['5-5']['count'])->toBe(2)
        ->and($inherent['risks']['by_rating']['critical'])->toBe(2)
        ->and($critical['inherent_score'])->toBe(25)
        ->and($plan['status'])->toBe('draft');
});

test('members read, ordinary members cannot keep the register and read-only roles never write', function (): void {
    $risk = grcRisk();

    grcAct($this->member, $this->tenant);
    $this->getJson('/api/v1/governance/risks')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/governance/risks/{$risk['id']}")->assertOk();
    $this->getJson('/api/v1/governance/dashboard')->assertOk();
    $this->getJson('/api/v1/governance/members')->assertOk()->assertJsonCount(4, 'data');
    $this->postJson('/api/v1/governance/risks', ['title' => 'x', 'category' => 'legal', 'likelihood' => 1, 'impact' => 1])->assertForbidden();
    $this->putJson("/api/v1/governance/risks/{$risk['id']}", ['title' => 'Changed'])->assertForbidden();
    $this->postJson('/api/v1/governance/controls', ['title' => 'x', 'control_type' => 'preventive', 'frequency' => 'annual'])->assertForbidden();
    $this->postJson('/api/v1/governance/obligations/seed-templates', ['frameworks' => ['gdpr']])->assertForbidden();

    $readOnly = grcMember($this->tenant, 'accountant_readonly');
    grcAct($readOnly, $this->tenant);
    $this->getJson('/api/v1/governance/risks')->assertOk();
    $this->postJson('/api/v1/governance/risks', ['title' => 'x', 'category' => 'legal', 'likelihood' => 1, 'impact' => 1])->assertForbidden();

    // a member granted governance.manage keeps the register
    $manager = grcMember($this->tenant, 'sales');
    $manager->tenants()->updateExistingPivot($this->tenant->id, ['permissions' => json_encode(['governance.manage'])]);
    grcAct($manager, $this->tenant);
    $this->postJson('/api/v1/governance/risks', ['title' => 'Granted', 'category' => 'legal', 'likelihood' => 1, 'impact' => 1])->assertCreated();
});

test('governance records never cross the company boundary', function (): void {
    $risk = grcRisk();
    $control = grcControl();
    $finding = grcFinding();

    $other = grcTenant('Altra Azienda');
    $stranger = grcMember($other, 'owner');
    grcAct($stranger, $other);

    $this->getJson('/api/v1/governance/risks')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/governance/risks/{$risk['id']}")->assertNotFound();
    $this->getJson("/api/v1/governance/controls/{$control['id']}")->assertNotFound();
    $this->postJson("/api/v1/governance/findings/{$finding['id']}/actions", ['title' => 'x', 'owner_id' => $stranger->id, 'due_date' => '2027-08-01'])->assertNotFound();
    $this->postJson("/api/v1/governance/risks/{$risk['id']}/transition", ['status' => 'closed'])->assertNotFound();

    // references to records of another company are refused
    $this->postJson('/api/v1/governance/findings', ['source' => 'control_test', 'control_id' => $control['id'], 'title' => 'x', 'severity' => 'low'])->assertUnprocessable();
    $this->postJson('/api/v1/governance/risks', ['title' => 'x', 'category' => 'legal', 'likelihood' => 1, 'impact' => 1, 'owner_id' => $this->owner->id])->assertUnprocessable();
});

test('domain events reach the audit ledger and every record is stamped with the company', function (): void {
    $risk = grcRisk(['owner_id' => $this->member->id]);
    grcTransition('risks', $risk['id'], 'assessed')->assertOk();
    $finding = grcFinding();
    grcAction($finding['id'], $this->member->id);

    $actions = AuditLedgerEntry::query()->where('tenant_id', $this->tenant->id)->where('module_code', 'MODULE_42_GOVERNANCE')->pluck('action')->all();
    expect($actions)->toContain('GOV_RISK_CREATED', 'GOV_RISK_TRANSITIONED', 'GOV_FINDING_RAISED', 'GOV_ACTION_RAISED', 'GOV_FINDING_TRANSITIONED');

    expect(GovRisk::withoutGlobalScopes()->findOrFail($risk['id'])->tenant_id)->toBe($this->tenant->id)
        ->and(Str::isUuid($risk['id']))->toBeTrue()
        ->and($risk['id'][14])->toBe('7');
});
