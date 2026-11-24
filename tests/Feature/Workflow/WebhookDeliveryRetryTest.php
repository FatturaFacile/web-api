<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Workflow\Jobs\DispatchOutboundWebhooksJob;
use Modules\Workflow\Models\WorkflowDeliveryAttempt;
use Modules\Workflow\Models\WorkflowWebhookSubscription;
use Modules\Workflow\Services\WorkflowWebhookDispatcherService;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'Webhook Retry Tenant',
        'legal_name' => 'Webhook Retry S.r.l.',
        'status' => 'active',
    ]);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->dispatcher = app(WorkflowWebhookDispatcherService::class);

    $this->subscription = WorkflowWebhookSubscription::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'name' => 'Retry Sink',
        'target_url' => 'https://receiver.test/webhook',
        'secret_key' => 'retry_secret_key',
        'subscribed_events' => ['workflow.completed'],
        'is_active' => true,
    ]);

    $this->dispatcher->dispatch($this->tenant->id, 'workflow.completed', ['instance_id' => 'retry-1']);
    $this->attempt = WorkflowDeliveryAttempt::where('subscription_id', $this->subscription->id)->firstOrFail();
});

test('a failed delivery counts the attempt, backs off exponentially and is dead-lettered at the last attempt', function () {
    Http::fake(['https://receiver.test/*' => Http::response('down', 503)]);
    $this->freezeTime();

    $expectedDelays = [60, 240, 960, 3840];

    foreach ($expectedDelays as $index => $delaySeconds) {
        expect($this->dispatcher->deliver($this->attempt))->toBeFalse();

        $this->attempt->refresh();
        expect($this->attempt->status)->toBe('pending')
            ->and($this->attempt->attempt_number)->toBe($index + 2)
            ->and($this->attempt->http_status)->toBe(503)
            ->and($this->attempt->next_retry_at->timestamp)->toBe(now()->addSeconds($delaySeconds)->timestamp);
    }

    expect($this->dispatcher->deliver($this->attempt))->toBeFalse();

    $this->attempt->refresh();
    expect($this->attempt->status)->toBe(WorkflowWebhookDispatcherService::STATUS_DEAD_LETTER)
        ->and($this->attempt->attempt_number)->toBe(WorkflowWebhookDispatcherService::MAX_ATTEMPTS)
        ->and($this->attempt->next_retry_at)->toBeNull();
    Http::assertSentCount(WorkflowWebhookDispatcherService::MAX_ATTEMPTS);
});

test('a network error is retried with backoff instead of every minute', function () {
    Http::fake(fn () => throw new RuntimeException('connection refused'));
    $this->freezeTime();

    $this->dispatcher->deliver($this->attempt);

    $this->attempt->refresh();
    expect($this->attempt->status)->toBe('pending')
        ->and($this->attempt->attempt_number)->toBe(2)
        ->and($this->attempt->next_retry_at->timestamp)->toBe(now()->addMinute()->timestamp);
});

test('claimed deliveries are marked processing and are not handed to a second worker', function () {
    $claimed = $this->dispatcher->claimDueAttempts();

    expect($claimed)->toHaveCount(1)
        ->and($claimed->first()->id)->toBe($this->attempt->id)
        ->and($this->attempt->refresh()->status)->toBe('processing')
        ->and($this->dispatcher->claimDueAttempts())->toBeEmpty();
});

test('running the job twice sends a delivery once', function () {
    Http::fake(['https://receiver.test/*' => Http::response(['ok' => true], 200)]);

    (new DispatchOutboundWebhooksJob)->handle($this->dispatcher);
    (new DispatchOutboundWebhooksJob)->handle($this->dispatcher);

    Http::assertSentCount(1);
    expect($this->attempt->refresh()->status)->toBe('successful');
});

test('a delivery left processing by a dead worker is taken over as a new attempt', function () {
    $this->attempt->forceFill(['status' => 'processing', 'updated_at' => now()->subMinutes(15)])->saveQuietly();

    $claimed = $this->dispatcher->claimDueAttempts();

    expect($claimed)->toHaveCount(1)
        ->and($claimed->first()->attempt_number)->toBe(2)
        ->and($claimed->first()->status)->toBe('processing');
});

test('a recent processing delivery is left to its worker', function () {
    $this->attempt->forceFill(['status' => 'processing', 'updated_at' => now()->subMinute()])->saveQuietly();

    expect($this->dispatcher->claimDueAttempts())->toBeEmpty();
});

test('a stale processing delivery without attempts left is dead-lettered', function () {
    $this->attempt->forceFill([
        'status' => 'processing',
        'attempt_number' => WorkflowWebhookDispatcherService::MAX_ATTEMPTS,
        'updated_at' => now()->subMinutes(15),
    ])->saveQuietly();

    expect($this->dispatcher->claimDueAttempts())->toBeEmpty()
        ->and($this->attempt->refresh()->status)->toBe(WorkflowWebhookDispatcherService::STATUS_DEAD_LETTER);
});
