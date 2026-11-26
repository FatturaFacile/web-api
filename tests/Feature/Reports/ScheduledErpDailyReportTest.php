<?php

declare(strict_types=1);

use App\Jobs\Reports\EvaluateTenantScheduledReportsJob;
use App\Jobs\Reports\ProcessScheduledReportChunkJob;
use App\Mail\ScheduledErpDailyReportMail;
use App\Models\EmailBlacklist;
use App\Models\NotificationPreference;
use App\Models\ScheduledReportAuditLog;
use App\Models\ScheduledReportDelivery;
use App\Models\Tenant;
use App\Models\TenantReportSetting;
use App\Models\User;
use App\Services\Reports\DailySummary\ErpDailySummaryAggregator;
use App\Services\Reports\DailySummary\ScheduleMatcherService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function actAsTenantMember(Tenant $tenant, string $role = 'owner'): User
{
    $user = User::factory()->create(['email' => Str::uuid().'@example.com']);
    $user->tenants()->attach($tenant->id, [
        'id' => (string) Str::uuid(),
        'role' => $role,
        'permissions' => json_encode([]),
    ]);

    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);

    return $user;
}

it('reads and updates user notification preferences with validation and audit logging', function (): void {
    $tenant = Tenant::factory()->create(['country' => 'CH']);
    $user = actAsTenantMember($tenant, 'member');

    $this->getJson('/api/v1/notification-preferences')
        ->assertOk()
        ->assertJsonPath('data.preference.enabled', true)
        ->assertJsonPath('data.preference.language', 'it-CH');

    $this->putJson('/api/v1/notification-preferences', [
        'enabled' => true,
        'frequency' => 'custom_cron',
        'custom_cron' => 'invalid-cron',
        'send_time' => '07:00',
        'language' => 'de-CH',
        'include_dashboard' => true,
        'include_billing' => true,
        'include_deadlines' => true,
        'include_crm' => false,
        'include_calendar' => true,
        'include_inventory' => false,
        'include_support' => true,
        'include_news' => true,
        'include_insights' => true,
    ])->assertStatus(422);

    $this->putJson('/api/v1/notification-preferences', [
        'enabled' => true,
        'frequency' => 'custom_cron',
        'custom_cron' => '0 7 * * 1-5',
        'send_time' => '07:00',
        'day_of_week' => null,
        'day_of_month' => null,
        'language' => 'de-CH',
        'include_dashboard' => true,
        'include_billing' => true,
        'include_deadlines' => true,
        'include_crm' => false,
        'include_calendar' => true,
        'include_inventory' => false,
        'include_support' => true,
        'include_news' => true,
        'include_insights' => true,
    ])->assertOk()->assertJsonPath('data.language', 'de-CH');

    expect(ScheduledReportAuditLog::query()
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $user->id)
        ->where('event_type', ScheduledReportAuditLog::EVENT_PREFERENCE_CREATED)
        ->exists())->toBeTrue();
});

it('allows tenant admins to manage tenant report settings and email blacklist while blocking non-admins', function (): void {
    $tenant = Tenant::factory()->create(['country' => 'CH']);
    actAsTenantMember($tenant, 'member');

    $this->putJson('/api/v1/tenant-report-settings', [
        'timezone' => 'Europe/Zurich',
        'default_frequency' => 'daily',
        'default_send_time' => '06:30',
        'primary_color' => '#1890ff',
        'secondary_color' => '#001529',
        'enabled_modules' => ['dashboard', 'billing'],
        'rate_limit_per_hour' => 500,
    ])->assertForbidden();

    actAsTenantMember($tenant, 'admin');

    $this->putJson('/api/v1/tenant-report-settings', [
        'timezone' => 'Europe/Zurich',
        'default_frequency' => 'daily',
        'default_send_time' => '06:30',
        'sender_name' => 'Helvetia ERP',
        'sender_email' => 'erp@helvetia.example',
        'primary_color' => '#1890ff',
        'secondary_color' => '#001529',
        'enabled_modules' => ['dashboard', 'billing', 'insights'],
        'rate_limit_per_hour' => 500,
    ])->assertOk()->assertJsonPath('data.sender_name', 'Helvetia ERP');

    $created = $this->postJson('/api/v1/email-blacklist', [
        'email' => 'bounced@example.com',
        'reason' => EmailBlacklist::REASON_HARD_BOUNCE,
    ])->assertCreated();

    $id = $created->json('data.id');
    $this->getJson('/api/v1/email-blacklist')->assertOk()->assertJsonCount(1, 'data');
    $this->deleteJson("/api/v1/email-blacklist/{$id}")->assertNoContent();
});

it('renders live preview and queues test and on-demand reports using Queue::fake()', function (): void {
    Queue::fake();
    $tenant = Tenant::factory()->create(['country' => 'IT', 'default_currency' => 'EUR']);
    $user = actAsTenantMember($tenant, 'owner');

    $this->getJson('/api/v1/reports/daily-summary/preview?language=fr-CH&include_crm=0')
        ->assertOk()
        ->assertJsonPath('data.summary.locale', 'fr-CH')
        ->assertJsonMissingPath('data.summary.sections.crm');

    $this->postJson('/api/v1/reports/daily-summary/send-test')
        ->assertStatus(202)
        ->assertJsonPath('data.is_test', true);

    $this->postJson('/api/v1/reports/daily-summary/send-now')
        ->assertStatus(202)
        ->assertJsonPath('data.is_test', false);

    Queue::assertPushed(ProcessScheduledReportChunkJob::class, 2);
});

it('dispatches two-tier scheduler batches, queues mail, enforces blacklist, rate limits, and idempotency', function (): void {
    Queue::fake();
    $tenant = Tenant::factory()->create(['status' => 'active', 'is_maintenance' => false]);
    $this->artisan('reports:dispatch-scheduled', ['--tenant' => [$tenant->id]])->assertSuccessful();
    Queue::assertPushed(EvaluateTenantScheduledReportsJob::class);

    Bus::fake();
    $user = User::factory()->create(['email' => 'active@example.com']);
    $blockedUser = User::factory()->create(['email' => 'blocked@example.com']);

    TenantReportSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'timezone' => 'Europe/Zurich',
        'rate_limit_per_hour' => 10,
    ]);

    $pref1 = NotificationPreference::factory()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'frequency' => NotificationPreference::FREQUENCY_DAILY,
        'send_time' => '07:00',
    ]);

    $pref2 = NotificationPreference::factory()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $blockedUser->id,
        'frequency' => NotificationPreference::FREQUENCY_DAILY,
        'send_time' => '07:00',
    ]);

    EmailBlacklist::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'blocked@example.com',
        'reason' => EmailBlacklist::REASON_MANUAL,
    ]);

    $utcIso = Carbon::create(2026, 11, 26, 7, 0, 0, 'Europe/Zurich')->setTimezone('UTC')->toIso8601String();
    (new EvaluateTenantScheduledReportsJob($tenant->id, $utcIso))->handle(
        app(TenantContext::class),
        app(ScheduleMatcherService::class),
    );

    Bus::assertBatched(static fn ($batch): bool => $batch->jobs->count() === 1);

    Mail::fake();
    RateLimiter::clear("scheduled-report-rate:{$tenant->id}");

    $chunkJob = new ProcessScheduledReportChunkJob($tenant->id, [$pref1->id, $pref2->id], '2026-11-26');
    $chunkJob->handle(
        app(TenantContext::class),
        app(ErpDailySummaryAggregator::class),
    );

    Mail::assertQueued(ScheduledErpDailyReportMail::class, 1);
    Mail::assertQueued(ScheduledErpDailyReportMail::class, static fn ($mail): bool => $mail->hasTo('active@example.com'));

    // Re-running same period key must not send duplicate email
    $chunkJob->handle(
        app(TenantContext::class),
        app(ErpDailySummaryAggregator::class),
    );
    Mail::assertQueued(ScheduledErpDailyReportMail::class, 1);
});

it('handles signed open tracking, click tracking with open-redirect protection, and one-click unsubscribe', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['email' => 'reader@example.com']);
    $pref = NotificationPreference::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id]);
    $delivery = ScheduledReportDelivery::factory()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'notification_preference_id' => $pref->id,
        'recipient_email' => $user->email,
    ]);

    $openUrl = URL::signedRoute('reports.track.open', ['delivery' => $delivery->id]);
    $this->get($openUrl)
        ->assertOk()
        ->assertHeader('Content-Type', 'image/gif');

    expect($delivery->refresh()->open_count)->toBe(1)
        ->and($delivery->status)->toBe(ScheduledReportDelivery::STATUS_OPENED);

    $clickUrl = URL::signedRoute('reports.track.click', ['delivery' => $delivery->id, 'target' => 'https://evil.example/phish']);
    $response = $this->get($clickUrl);
    $response->assertRedirect();
    expect($response->headers->get('Location'))->toEndWith('/dashboard')
        ->and($delivery->refresh()->click_count)->toBe(1)
        ->and($delivery->status)->toBe(ScheduledReportDelivery::STATUS_CLICKED);

    $unsubUrl = URL::signedRoute('reports.unsubscribe', ['preference' => $pref->id]);
    $this->post($unsubUrl)->assertOk()->assertJsonPath('data.unsubscribed', true);

    expect($pref->refresh()->enabled)->toBeFalse()
        ->and($pref->unsubscribed_at)->not->toBeNull()
        ->and(EmailBlacklist::isBlacklisted('reader@example.com', $tenant->id))->toBeTrue();
});
