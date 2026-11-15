<?php

declare(strict_types=1);

namespace Modules\DataExport\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\DataExport\Models\ExportJob;
use Modules\DataExport\Services\ExportJobService;

/**
 * Builds the file of one export job under the tenant of the job. The outcome, failures included, is stored on the job.
 */
final class RunExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $exportJobId, public readonly string $tenantId)
    {
        $this->afterCommit();
    }

    public function handle(ExportJobService $service): void
    {
        $service->withTenant($this->tenantId, function () use ($service): void {
            $job = ExportJob::query()->find($this->exportJobId);

            if ($job !== null) {
                $service->run($job);
            }
        });
    }
}
