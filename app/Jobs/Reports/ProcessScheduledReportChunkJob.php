<?php

declare(strict_types=1);

namespace App\Jobs\Reports;

use App\Mail\ScheduledErpDailyReportMail;
use App\Models\EmailBlacklist;
use App\Models\NotificationPreference;
use App\Models\ScheduledReportAuditLog;
use App\Models\ScheduledReportDelivery;
use App\Models\Tenant;
use App\Models\TenantReportSetting;
use App\Models\User;
use App\Services\Reports\DailySummary\ErpDailySummaryAggregator;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Tier 2 worker job: processes a chunk of due user report preferences with idempotency locks,
 * blacklist checks, per-tenant rate limiting, exponential backoff retries, and full audit logging.
 */
final class ProcessScheduledReportChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300, 900];

    /**
     * @param  list<string>  $preferenceIds
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly array $preferenceIds,
        public readonly string $periodKey,
        public readonly bool $isTest = false,
        public readonly bool $forceOnDemand = false,
    ) {}

    public function handle(TenantContext $tenantContext, ErpDailySummaryAggregator $aggregator): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $tenant = Tenant::query()->whereKey($this->tenantId)->first();
        if ($tenant === null) {
            return;
        }

        $tenantContext->runWithTenant($tenant, function () use ($tenant, $aggregator): void {
            $settings = TenantReportSetting::query()->where('tenant_id', $tenant->id)->first();
            $rateLimit = max(1, (int) ($settings !== null ? $settings->rate_limit_per_hour : 1000));

            $preferences = NotificationPreference::query()
                ->with('user')
                ->where('tenant_id', $tenant->id)
                ->whereIn('id', $this->preferenceIds)
                ->get();

            foreach ($preferences as $preference) {
                $this->processPreference($tenant, $preference, $settings, $aggregator, $rateLimit);
            }
        });
    }

    private function processPreference(
        Tenant $tenant,
        NotificationPreference $preference,
        ?TenantReportSetting $settings,
        ErpDailySummaryAggregator $aggregator,
        int $rateLimit,
    ): void {
        $user = $preference->user;
        if (! $user instanceof User || trim((string) $user->email) === '') {
            return;
        }

        if (! $this->isTest && ! $this->forceOnDemand && (! $preference->enabled || $preference->unsubscribed_at !== null)) {
            return;
        }

        if (! $this->isTest && EmailBlacklist::isBlacklisted((string) $user->email, $tenant->id)) {
            return;
        }

        $lockKey = sprintf('scheduled-report:%s:%s:%s', $tenant->id, $user->id, $this->periodKey);
        $lock = Cache::lock($lockKey, 300);

        if (! $this->isTest && ! $this->forceOnDemand && ! $lock->get()) {
            return;
        }

        try {
            if (! $this->isTest && ! $this->forceOnDemand) {
                $fresh = NotificationPreference::query()->whereKey($preference->id)->first();
                if ($fresh === null || $fresh->last_period_key === $this->periodKey) {
                    return;
                }
            }

            $rateKey = "scheduled-report-rate:{$tenant->id}";
            if (! $this->isTest && RateLimiter::tooManyAttempts($rateKey, $rateLimit)) {
                Log::warning('Scheduled ERP report hourly rate limit exceeded for tenant.', [
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                ]);

                return;
            }

            if (! $this->isTest) {
                RateLimiter::hit($rateKey, 3600);
            }

            $delivery = ScheduledReportDelivery::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'notification_preference_id' => $preference->id,
                'recipient_email' => $user->email,
                'frequency' => $preference->frequency,
                'period_key' => $this->periodKey,
                'language' => $preference->language ?: 'it-CH',
                'is_test' => $this->isTest,
                'status' => ScheduledReportDelivery::STATUS_QUEUED,
                'queued_at' => now(),
            ]);

            ScheduledReportAuditLog::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'delivery_id' => $delivery->id,
                'event_type' => ScheduledReportAuditLog::EVENT_REPORT_QUEUED,
                'recipient_email' => $user->email,
                'period_key' => $this->periodKey,
                'occurred_at' => now(),
                'metadata' => ['is_test' => $this->isTest],
            ]);

            try {
                $summary = $aggregator->aggregate(
                    $tenant,
                    $user,
                    $preference->language ?: 'it-CH',
                    $settings,
                    $preference->selectedModules(),
                );
            } catch (Throwable $e) {
                $delivery->update([
                    'status' => ScheduledReportDelivery::STATUS_GENERATION_FAILED,
                    'error_message' => $e->getMessage(),
                    'error_trace' => substr($e->getTraceAsString(), 0, 2000),
                ]);

                ScheduledReportAuditLog::query()->create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'delivery_id' => $delivery->id,
                    'event_type' => ScheduledReportAuditLog::EVENT_REPORT_GENERATION_FAILED,
                    'recipient_email' => $user->email,
                    'period_key' => $this->periodKey,
                    'error_trace' => substr($e->getMessage()."\n".$e->getTraceAsString(), 0, 2000),
                    'occurred_at' => now(),
                ]);

                Log::error('Scheduled ERP report generation failed.', [
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);

                return;
            }

            $unsubscribeUrl = URL::signedRoute('reports.unsubscribe', ['preference' => $preference->id]);
            $openPixelUrl = URL::signedRoute('reports.track.open', ['delivery' => $delivery->id]);
            $clickBaseUrl = URL::signedRoute('reports.track.click', ['delivery' => $delivery->id]);

            try {
                $mailable = new ScheduledErpDailyReportMail(
                    tenant: $tenant,
                    user: $user,
                    summary: $summary,
                    reportLocale: $preference->language ?: 'it-CH',
                    settings: $settings,
                    unsubscribeUrl: $unsubscribeUrl,
                    openPixelUrl: $openPixelUrl,
                    clickBaseUrl: $clickBaseUrl,
                    isTest: $this->isTest,
                );

                Mail::to($user->email)->queue($mailable);

                $delivery->update([
                    'status' => ScheduledReportDelivery::STATUS_SENT,
                    'provider_status' => 'queued_to_mailer',
                    'sent_at' => now(),
                ]);

                if (! $this->isTest && ! $this->forceOnDemand) {
                    $preference->update([
                        'last_sent_at' => now(),
                        'last_period_key' => $this->periodKey,
                    ]);
                }

                ScheduledReportAuditLog::query()->create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'delivery_id' => $delivery->id,
                    'event_type' => ScheduledReportAuditLog::EVENT_REPORT_SENT,
                    'recipient_email' => $user->email,
                    'period_key' => $this->periodKey,
                    'provider_status' => 'queued_to_mailer',
                    'occurred_at' => now(),
                    'metadata' => ['is_test' => $this->isTest],
                ]);
            } catch (Throwable $e) {
                $delivery->update([
                    'status' => ScheduledReportDelivery::STATUS_FAILED,
                    'error_message' => $e->getMessage(),
                    'error_trace' => substr($e->getTraceAsString(), 0, 2000),
                ]);

                ScheduledReportAuditLog::query()->create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'delivery_id' => $delivery->id,
                    'event_type' => ScheduledReportAuditLog::EVENT_REPORT_FAILED,
                    'recipient_email' => $user->email,
                    'period_key' => $this->periodKey,
                    'error_trace' => substr($e->getMessage()."\n".$e->getTraceAsString(), 0, 2000),
                    'occurred_at' => now(),
                ]);

                Log::error('Scheduled ERP report mail dispatch failed.', [
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } finally {
            if (! $this->isTest && ! $this->forceOnDemand) {
                $lock->release();
            }
        }
    }
}
