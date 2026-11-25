<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\Activity;
use Modules\CustomerSatisfaction\Models\DetractorRecoveryCase;

require_once __DIR__.'/CustomerSatisfactionSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    csSetUp($this);
    $this->accountManager = csUser($this->tenant, 'sales_manager');
    $this->supportLead = csUser($this->tenant, 'support_manager');
    $this->account = Account::query()->create([
        'contact_id' => $this->customer->id,
        'account_number' => 'ACC-'.Str::upper(Str::random(5)),
        'name' => 'Hotel Test',
        'status' => 'active',
        'churn_risk' => 'low',
        'owner_user_id' => $this->accountManager->id,
    ]);
});

/**
 * The click of a customer on a score, through the public one-click link.
 */
function csClick(object $test, string $type, int $score): string
{
    $template = csTemplate(['code' => strtoupper($type).'-'.Str::upper(Str::random(5)), 'survey_type' => $type]);
    $dispatch = csDispatch($template, $test->customer);
    $test->getJson('/api/v1/public/surveys/'.$dispatch->dispatch_token.'/rate/'.$score)->assertOk();

    return $dispatch->id;
}

it('opens a recovery case with a 24 hour SLA when an NPS detractor clicks 6 or less', function (): void {
    csClick($this, 'nps', 6);

    $case = DetractorRecoveryCase::query()->sole();

    expect($case->status->value)->toBe('open')
        ->and($case->priority->value)->toBe('high')
        ->and($case->customer_id)->toBe($this->customer->id)
        ->and($case->sla_due_at->diffInMinutes(now()->addHours(24), false))->toBeLessThan(2)
        ->and($case->sla_due_at->diffInMinutes(now()->addHours(24), false))->toBeGreaterThan(-2)
        ->and($case->assigned_account_manager_id)->toBe($this->accountManager->id)
        ->and($case->assigned_support_lead_id)->toBe($this->supportLead->id);
});

it('treats the worst answers as urgent', function (): void {
    csClick($this, 'nps', 2);

    expect(DetractorRecoveryCase::query()->sole()->priority->value)->toBe('urgent');
});

it('opens cases for CSAT 2 or less and CES 5 or more, and for nothing better', function (): void {
    csClick($this, 'csat', 3);
    csClick($this, 'csat', 4);
    csClick($this, 'ces', 4);
    csClick($this, 'nps', 7);
    csClick($this, 'nps', 9);
    expect(DetractorRecoveryCase::query()->count())->toBe(0);

    csClick($this, 'csat', 2);
    csClick($this, 'ces', 5);
    csClick($this, 'ces', 7);

    $cases = DetractorRecoveryCase::query()->with('response')->get();

    expect($cases)->toHaveCount(3)
        ->and($cases->pluck('response.survey_type')->map->value->sort()->values()->all())->toBe(['ces', 'ces', 'csat']);
});

it('honours the detractor threshold of the template', function (): void {
    $template = csTemplate(['code' => 'NPS-STRICT', 'detractor_threshold' => 8]);
    $dispatch = csDispatch($template, $this->customer);

    $this->getJson('/api/v1/public/surveys/'.$dispatch->dispatch_token.'/rate/8')->assertOk();

    expect(DetractorRecoveryCase::query()->count())->toBe(1);
});

it('notifies the account manager and the support lead', function (): void {
    csClick($this, 'nps', 4);

    $case = DetractorRecoveryCase::query()->sole();

    foreach ([$this->accountManager, $this->supportLead] as $user) {
        $notification = $user->notifications()->get()->first();

        expect($notification)->not->toBeNull()
            ->and($notification->data['key'])->toBe('detractor_recovery_opened')
            ->and($notification->data['case_id'])->toBe($case->id);
    }
});

it('creates the mandatory follow-up task in the CRM, due at the SLA deadline', function (): void {
    csClick($this, 'nps', 5);

    $case = DetractorRecoveryCase::query()->sole();
    $task = Activity::query()->findOrFail($case->crm_task_id);

    expect($task->type)->toBe('task')
        ->and($task->status)->toBe('pending')
        ->and($task->priority)->toBe('high')
        ->and($task->related_type)->toBe('account')
        ->and($task->related_id)->toBe($this->account->id)
        ->and($task->assigned_user_id)->toBe($this->accountManager->id)
        ->and($task->due_date->equalTo($case->sla_due_at))->toBeTrue();
});

it('raises the churn risk of the account and records the move on the case', function (): void {
    csClick($this, 'nps', 5);

    $case = DetractorRecoveryCase::query()->sole();

    expect($case->churn_risk_updated)->toBeTrue()
        ->and($case->previous_churn_risk)->toBe('low')
        ->and($case->new_churn_risk)->toBe('high')
        ->and($this->account->refresh()->churn_risk)->toBe('high');
});

it('makes the churn risk critical for an urgent case and never lowers an existing risk', function (): void {
    $this->account->forceFill(['churn_risk' => 'critical'])->save();
    csClick($this, 'nps', 5);

    $case = DetractorRecoveryCase::query()->sole();
    expect($case->churn_risk_updated)->toBeFalse()->and($case->new_churn_risk)->toBe('critical');

    $this->account->forceFill(['churn_risk' => 'medium'])->save();
    csClick($this, 'ces', 7);

    expect($this->account->refresh()->churn_risk)->toBe('critical');
});

it('still opens the case when the customer has no CRM account', function (): void {
    $this->account->forceDelete();

    csClick($this, 'nps', 1);

    $case = DetractorRecoveryCase::query()->sole();

    expect($case->crm_task_id)->not->toBeNull()
        ->and($case->new_churn_risk)->toBeNull()
        ->and($case->assigned_account_manager_id)->toBeNull();
});

it('opens one case per answer, however often the link is clicked', function (): void {
    $template = csTemplate(['code' => 'NPS-ONCE']);
    $dispatch = csDispatch($template, $this->customer);

    foreach ([1, 1, 2] as $score) {
        $this->getJson('/api/v1/public/surveys/'.$dispatch->dispatch_token.'/rate/'.$score)->assertOk();
    }

    expect(DetractorRecoveryCase::query()->count())->toBe(1);
});

it('lists the queue with the most urgent SLA first and filters it', function (): void {
    csClick($this, 'nps', 3);
    csClick($this, 'nps', 5);
    DetractorRecoveryCase::query()->orderBy('created_at')->first()->forceFill(['sla_due_at' => now()->addHour()])->save();

    $rows = $this->getJson('/api/v1/customer-satisfaction/recovery-cases')->assertOk()->assertJsonCount(2, 'data')->json('data');

    expect(strtotime($rows[0]['sla_due_at']))->toBeLessThan(strtotime($rows[1]['sla_due_at']))
        ->and($rows[0]['customer_name'])->toBe('Hotel Test')
        ->and($rows[0]['account_manager_name'])->toBe($this->accountManager->name)
        ->and($rows[0]['response']['score'])->toBeInt();

    $this->getJson('/api/v1/customer-satisfaction/recovery-cases?priority=urgent')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/customer-satisfaction/recovery-cases?status=resolved')->assertOk()->assertJsonCount(0, 'data');
});

it('walks a case to resolution with the root cause, stamps the first contact and completes the CRM task', function (): void {
    csClick($this, 'nps', 4);
    $case = DetractorRecoveryCase::query()->sole();
    $url = '/api/v1/customer-satisfaction/recovery-cases/'.$case->id;

    $this->postJson($url.'/transition', ['status' => 'in_progress'])->assertOk()->assertJsonPath('data.status', 'in_progress');
    expect($case->refresh()->first_contacted_at)->not->toBeNull();

    $this->postJson($url.'/transition', ['status' => 'resolved'])->assertStatus(422)->assertJsonPath('code', 'resolution_requires_root_cause');

    $this->postJson($url.'/transition', [
        'status' => 'resolved',
        'root_cause_code' => 'delivery_delay',
        'resolution_summary' => 'Called the customer, refunded the transport.',
    ])->assertOk()->assertJsonPath('data.status', 'resolved')->assertJsonPath('data.root_cause_code', 'delivery_delay');

    $case->refresh();
    expect($case->resolved_at)->not->toBeNull()
        ->and(Activity::query()->findOrFail($case->crm_task_id)->status)->toBe('completed');

    $this->postJson($url.'/transition', ['status' => 'closed'])->assertOk();
    $this->postJson($url.'/transition', ['status' => 'in_progress'])->assertStatus(409)->assertJsonPath('code', 'invalid_status_transition');
});

it('assigns other owners to a case, members of the company only', function (): void {
    csClick($this, 'nps', 4);
    $case = DetractorRecoveryCase::query()->sole();
    $url = '/api/v1/customer-satisfaction/recovery-cases/'.$case->id.'/assign';
    $other = csUser($this->tenant, 'operations_manager');

    $this->putJson($url, ['assigned_account_manager_id' => $other->id, 'assigned_support_lead_id' => null])->assertOk()
        ->assertJsonPath('data.assigned_account_manager_id', $other->id)->assertJsonPath('data.assigned_support_lead_id', null);

    $stranger = csUser($this->otherTenant, 'sales_manager');
    $this->putJson($url, ['assigned_account_manager_id' => $stranger->id])->assertStatus(422);
});

it('never shows the cases of another company', function (): void {
    csClick($this, 'nps', 4);
    $case = DetractorRecoveryCase::query()->sole();

    $foreign = csUser($this->otherTenant, 'accountant');
    Sanctum::actingAs($foreign);
    $this->withHeader('X-Tenant-Id', $this->otherTenant->id);
    app(TenantContext::class)->setTenant($this->otherTenant);

    $this->getJson('/api/v1/customer-satisfaction/recovery-cases')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/customer-satisfaction/recovery-cases/'.$case->id)->assertNotFound();
    $this->postJson('/api/v1/customer-satisfaction/recovery-cases/'.$case->id.'/transition', ['status' => 'in_progress'])->assertNotFound();
});
