<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Support\Str;
use Modules\CRM\Contracts\ActivityServiceInterface;
use Modules\CRM\DTOs\CreateActivityDTO;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\Activity;

beforeEach(function (): void {
    $this->tenant = Tenant::firstOrCreate(
        ['id' => (string) Str::uuid7()],
        ['name' => 'CRM Activity Test Tenant', 'legal_name' => 'CRM Activity Test Tenant SpA']
    );
    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::firstOrCreate(
        ['email' => 'activityagent@example.com'],
        ['id' => (string) Str::uuid7(), 'name' => 'CRM Activity Agent', 'password' => bcrypt('secret')]
    );

    $this->account = Account::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $this->tenant->id,
        'account_number' => 'ACC-ACT-01',
        'name' => 'Apex Solutions Srl',
        'tier' => 'enterprise',
        'status' => 'active',
    ]);

    $this->activityService = app(ActivityServiceInterface::class);
});

test('logs task, call, meeting, and updates parent last_activity_at', function (): void {
    $activity = $this->activityService->logActivity(new CreateActivityDTO(
        tenantId: (string) $this->tenant->id,
        companyId: (string) $this->tenant->id,
        subject: 'Product Demonstration for Board',
        type: 'meeting',
        priority: 'urgent',
        dueDate: now()->addDays(1)->toDateTimeString(),
        relatedType: 'account',
        relatedId: (string) $this->account->id,
        durationMinutes: 60,
        location: 'Virtual Zoom Room',
        notes: 'Demonstrating ERP V2 architecture capabilities.'
    ), (string) $this->user->id);

    expect($activity)->not->toBeNull();
    expect($activity->type)->toBe('meeting');
    expect($activity->status)->toBe('pending');
    expect($this->account->fresh()->last_activity_at)->not->toBeNull();

    // Verify MeetingScheduledEvent in outbox
    $outbox = TransactionalOutbox::where('event_type', 'crm.meeting.scheduled')
        ->where('aggregate_id', (string) $activity->id)
        ->first();
    expect($outbox)->not->toBeNull();
});

test('completes activity and dispatches ActivityCompletedEvent', function (): void {
    $activity = Activity::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $this->tenant->id,
        'subject' => 'Executive Check-in Call',
        'type' => 'call',
        'status' => 'pending',
        'priority' => 'medium',
        'related_type' => 'account',
        'relatedId' => (string) $this->account->id,
    ]);

    $completed = $this->activityService->completeActivity(
        $activity,
        'Client confirmed budget approval.',
        (string) $this->user->id
    );

    expect($completed->status)->toBe('completed');
    expect($completed->outcome)->toBe('Client confirmed budget approval.');
    expect($completed->completed_at)->not->toBeNull();

    $outbox = TransactionalOutbox::where('event_type', 'crm.activity.completed')
        ->where('aggregate_id', (string) $activity->id)
        ->first();
    expect($outbox)->not->toBeNull();
});

test('retrieves timeline ordered by recency', function (): void {
    Activity::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $this->tenant->id,
        'subject' => 'Note 1: Initial Discovery',
        'type' => 'note',
        'status' => 'completed',
        'related_type' => 'account',
        'related_id' => (string) $this->account->id,
        'due_date' => now()->subDays(3),
    ]);

    Activity::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $this->tenant->id,
        'subject' => 'Call 2: Follow-up',
        'type' => 'call',
        'status' => 'completed',
        'related_type' => 'account',
        'related_id' => (string) $this->account->id,
        'due_date' => now()->subDay(),
    ]);

    $timeline = $this->activityService->getTimeline((string) $this->tenant->id, 'account', (string) $this->account->id);

    expect($timeline->count())->toBe(2);
    expect($timeline->first()->subject)->toBe('Call 2: Follow-up');
});
