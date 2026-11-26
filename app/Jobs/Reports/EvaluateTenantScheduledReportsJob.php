<?php

declare(strict_types=1);

namespace App\Jobs\Reports;

use App\Models\NotificationPreference;
use App\Models\Tenant;
use App\Models\TenantReportSetting;
use App\Services\Reports\DailySummary\ScheduleMatcherService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

/**
 * Tier 1 scheduler job: evaluates all active preferences for a single tenant in its configured timezone
 * and dispatches chunked batch jobs for due users.
 */
final class EvaluateTenantScheduledReportsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly string $tenantId,
        public readonly ?string $evaluatedAtIso = null,
    ) {}

    public function handle(TenantContext $tenantContext, ScheduleMatcherService $matcher): void
    {
        $tenant = Tenant::query()
            ->whereKey($this->tenantId)
            ->where('status', 'active')
            ->where('is_maintenance', false)
            ->first();

        if ($tenant === null) {
            return;
        }

        $tenantContext->runWithTenant($tenant, function () use ($tenant, $matcher): void {
            $settings = TenantReportSetting::query()->where('tenant_id', $tenant->id)->first();
            $timezone = $settings?->timezone ?: TenantReportSetting::defaultTimezoneForTenant($tenant);
            $utcNow = $this->evaluatedAtIso !== null ? Carbon::parse($this->evaluatedAtIso) : Carbon::now('UTC');
            $localNow = $utcNow->copy()->setTimezone($timezone);

            $dueByPeriod = [];

            NotificationPreference::query()
                ->where('tenant_id', $tenant->id)
                ->where('enabled', true)
                ->whereNull('unsubscribed_at')
                ->chunkById(200, function ($preferences) use ($matcher, $localNow, &$dueByPeriod): void {
                    foreach ($preferences as $preference) {
                        $periodKey = $matcher->duePeriodKey($preference, $localNow);
                        if ($periodKey !== null) {
                            $dueByPeriod[$periodKey][] = (string) $preference->id;
                        }
                    }
                });

            $jobs = [];
            foreach ($dueByPeriod as $periodKey => $preferenceIds) {
                foreach (array_chunk($preferenceIds, 100) as $chunk) {
                    $jobs[] = new ProcessScheduledReportChunkJob((string) $tenant->id, $chunk, $periodKey);
                }
            }

            if ($jobs !== []) {
                Bus::batch($jobs)
                    ->name("scheduled-erp-reports:{$tenant->id}")
                    ->allowFailures()
                    ->dispatch();
            }
        });
    }
}
