<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\CRM\Jobs\SendCrmActivityRemindersJob;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\Activity;
use Modules\CRM\Models\Lead;
use Modules\CRM\Notifications\ActivityReminderNotification;

uses(RefreshDatabase::class);

/**
 * Configurable lead scoring with explanations, activity logging with assignees, reminders and the unified timeline.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Scoring Tenant', 'legal_name' => 'Scoring Tenant SpA']);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::create(['name' => 'Sales Rep', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'sales']);
    $this->actingAs($this->user);
    $this->withHeader('X-Tenant-Id', (string) $this->tenant->id);
});

test('without tenant rules leads are scored by the default model with an explanation', function (): void {
    $response = $this->postJson('/api/v1/crm/leads', [
        'first_name' => 'Anna', 'company_name' => 'Neri Srl', 'email' => 'anna@neri.it', 'title' => 'CEO', 'estimated_budget' => 20000,
    ])->assertCreated();

    $response->assertJsonPath('score', 70)
        ->assertJsonPath('rating', 'hot')
        ->assertJsonPath('score_model', 'default');
    expect(collect($response->json('score_breakdown'))->pluck('rule')->all())->toContain('Senior job title', 'Business email', 'Company known', 'Budget from 10,000');

    $this->getJson('/api/v1/crm/leads/'.$response->json('id').'/score')
        ->assertOk()
        ->assertJsonPath('score', 70)
        ->assertJsonPath('current.score', 70);
});

test('tenant rules replace the default model and are recalculated on demand and on activity events', function (): void {
    $lead = Lead::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'first_name' => 'Paolo', 'country' => 'CH', 'email' => 'paolo@gmail.com', 'status' => 'new']);

    $this->postJson('/api/v1/crm/lead-scoring-rules', ['name' => 'Swiss market', 'rule_type' => 'field', 'field' => 'country', 'operator' => 'equals', 'value' => 'CH', 'points' => 30])->assertCreated();
    $this->postJson('/api/v1/crm/lead-scoring-rules', ['name' => 'Free mailbox', 'rule_type' => 'field', 'field' => 'email', 'operator' => 'business_email', 'points' => 15])->assertCreated();
    $this->postJson('/api/v1/crm/lead-scoring-rules', ['name' => 'Meetings held', 'rule_type' => 'activity', 'activity_type' => 'meeting', 'points' => 20, 'max_points' => 30])->assertCreated();
    $this->postJson('/api/v1/crm/lead-scoring-rules', ['name' => 'Broken rule', 'rule_type' => 'field', 'field' => 'password', 'operator' => 'equals', 'points' => 5])->assertUnprocessable();

    $this->postJson('/api/v1/crm/lead-scoring-rules/recalculate')->assertOk()->assertJsonPath('recalculated', 1);
    app(TenantContext::class)->setTenant($this->tenant);
    expect($lead->fresh()->score)->toBe(30)->and($lead->fresh()->score_breakdown['model'])->toBe('tenant_rules');

    // Each logged meeting adds 20 points, capped at 30 by the rule.
    foreach ([1, 2] as $meeting) {
        $this->postJson('/api/v1/crm/activities', ['subject' => "Meeting {$meeting}", 'type' => 'meeting', 'related_type' => 'lead', 'related_id' => $lead->id])->assertCreated();
    }

    app(TenantContext::class)->setTenant($this->tenant);
    $fresh = $lead->fresh();
    expect($fresh->score)->toBe(60)
        ->and(collect($fresh->score_breakdown['items'])->firstWhere('rule', 'Meetings held')['points'])->toBe(30)
        ->and(TransactionalOutbox::where('event_type', 'crm.activity.logged')->count())->toBe(2);

    $this->getJson("/api/v1/crm/leads/{$lead->id}/score")
        ->assertOk()
        ->assertJsonPath('model', 'tenant_rules')
        ->assertJsonCount(2, 'breakdown');
});

test('scoring rules are isolated per tenant', function (): void {
    $this->postJson('/api/v1/crm/lead-scoring-rules', ['name' => 'Mine', 'rule_type' => 'field', 'field' => 'phone', 'operator' => 'filled', 'points' => 10])->assertCreated();

    $other = Tenant::create(['name' => 'Other', 'legal_name' => 'Other SpA']);
    $otherUser = User::create(['name' => 'Other', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $otherUser->tenants()->attach($other->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);

    $this->actingAs($otherUser)->withHeader('X-Tenant-Id', (string) $other->id)
        ->getJson('/api/v1/crm/lead-scoring-rules')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('activities validate the related record and the assignee against the tenant', function (): void {
    $other = Tenant::create(['name' => 'Other', 'legal_name' => 'Other SpA']);
    $foreignLead = app(TenantContext::class)->runWithTenant($other, fn () => Lead::create(['id' => (string) Str::uuid7(), 'first_name' => 'X', 'status' => 'new']));
    $outsider = User::create(['name' => 'Outsider', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);

    $this->postJson('/api/v1/crm/activities', ['subject' => 'Call', 'type' => 'call', 'related_type' => 'lead', 'related_id' => $foreignLead->id])
        ->assertUnprocessable()->assertJsonValidationErrors('related_id');
    $this->postJson('/api/v1/crm/activities', ['subject' => 'Call', 'type' => 'call', 'assigned_user_id' => $outsider->id])
        ->assertUnprocessable()->assertJsonValidationErrors('assigned_user_id');
});

test('reminders notify the assignee once through the notification infrastructure', function (): void {
    Notification::fake();
    $colleague = User::create(['name' => 'Colleague', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $colleague->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'sales']);

    $due = $this->postJson('/api/v1/crm/activities', [
        'subject' => 'Follow up offer', 'type' => 'follow_up', 'assigned_user_id' => $colleague->id,
        'due_date' => now()->addHour()->toDateTimeString(), 'reminder_at' => now()->subMinute()->toDateTimeString(),
    ])->assertCreated()->assertJsonPath('assigned_user_id', $colleague->id);
    $this->postJson('/api/v1/crm/activities', ['subject' => 'Later', 'type' => 'task', 'reminder_at' => now()->addDay()->toDateTimeString()])->assertCreated();

    (new SendCrmActivityRemindersJob((string) $this->tenant->id))->handle();
    (new SendCrmActivityRemindersJob((string) $this->tenant->id))->handle();

    Notification::assertSentToTimes($colleague, ActivityReminderNotification::class, 1);
    Notification::assertNotSentTo($this->user, ActivityReminderNotification::class);
    app(TenantContext::class)->setTenant($this->tenant);
    expect(Activity::find($due->json('id'))->reminder_sent_at)->not->toBeNull();

    // Rescheduling re-arms the reminder.
    $this->putJson('/api/v1/crm/activities/'.$due->json('id'), ['reminder_at' => now()->subSecond()->toDateTimeString(), 'priority' => 'urgent'])
        ->assertOk()->assertJsonPath('priority', 'urgent')->assertJsonPath('reminder_sent_at', null);
    (new SendCrmActivityRemindersJob((string) $this->tenant->id))->handle();
    Notification::assertSentToTimes($colleague, ActivityReminderNotification::class, 2);
});

test('completed activities cannot be changed and overdue ones are filterable', function (): void {
    $activity = $this->postJson('/api/v1/crm/activities', ['subject' => 'Old call', 'type' => 'call', 'due_date' => now()->subDays(2)->toDateTimeString()])->assertCreated();

    $this->getJson('/api/v1/crm/activities?overdue=1&assigned_user_id=me')->assertOk()->assertJsonPath('meta.total', 1);

    $this->postJson('/api/v1/crm/activities/'.$activity->json('id').'/complete', ['outcome' => 'Done'])->assertOk();
    $this->putJson('/api/v1/crm/activities/'.$activity->json('id'), ['subject' => 'Changed'])->assertStatus(409);
    $this->getJson('/api/v1/crm/activities?overdue=1')->assertOk()->assertJsonPath('meta.total', 0);
});

test('the unified timeline of a contact gathers activities of its accounts, opportunities and leads', function (): void {
    $contact = Contact::factory()->create(['tenant_id' => $this->tenant->id]);
    $account = Account::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'account_number' => 'ACC-TL', 'name' => 'Timeline Srl', 'contact_id' => $contact->id]);
    $lead = Lead::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'first_name' => 'T', 'status' => 'converted', 'converted_contact_id' => $contact->id]);

    $this->postJson('/api/v1/crm/activities', ['subject' => 'On contact', 'type' => 'note', 'related_type' => 'contact', 'related_id' => $contact->id])->assertCreated();
    $this->postJson('/api/v1/crm/activities', ['subject' => 'On account', 'type' => 'call', 'related_type' => 'account', 'related_id' => $account->id])->assertCreated();
    $this->postJson('/api/v1/crm/activities', ['subject' => 'On lead', 'type' => 'email', 'related_type' => 'lead', 'related_id' => $lead->id])->assertCreated();
    $this->postJson('/api/v1/crm/activities', ['subject' => 'Unrelated', 'type' => 'task'])->assertCreated();

    $this->getJson("/api/v1/crm/activities/timeline?related_type=contact&related_id={$contact->id}")->assertOk()->assertJsonCount(1);

    $unified = $this->getJson("/api/v1/crm/activities/timeline?related_type=contact&related_id={$contact->id}&include_related=1")->assertOk();
    expect(collect($unified->json())->pluck('subject')->sort()->values()->all())->toBe(['On account', 'On contact', 'On lead']);
});
