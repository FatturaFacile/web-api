<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Commands;

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Modules\Analytics\Jobs\ExecuteScheduledReportJob;
use Modules\Analytics\Models\ExportSchedule;

/**
 * Queues {@see ExecuteScheduledReportJob} for a schedule the caller may manage. The job generates the artifact in the
 * locale and format of the schedule and delivers it to every channel; the outcome lands on the schedule and in the
 * execution log (the export runs).
 */
final readonly class TriggerScheduledReportRunHandler
{
    public function __construct(private TenantContext $tenantContext) {}

    public function handle(TriggerScheduledReportRunCommand $command): ExportSchedule
    {
        $schedule = ExportSchedule::query()->findOrFail($command->scheduleId);
        Gate::forUser($command->user)->authorize('manage', $schedule);
        ExecuteScheduledReportJob::dispatch((string) $this->tenantContext->getTenantId(), (string) $schedule->id);

        return $schedule;
    }
}
