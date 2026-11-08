<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Manufacturing\Contracts\MrpServiceInterface;
use Modules\Manufacturing\DTOs\RunMrpDTO;
use Throwable;

/**
 * Runs MRP for one tenant. Two concurrent runs would plan the same demand twice, so the job is unique per tenant. A run
 * is a single transaction, so a failed one leaves nothing behind and can be retried.
 */
final class ProcessMrpRunJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 2;

    /**
     * Seconds before the retry.
     */
    public int $backoff = 300;

    /**
     * Released by the end of the run; this only bounds a lock left behind by a worker that died.
     */
    public int $uniqueFor = 3600;

    public function __construct(
        public string $tenantId,
        public string $horizonStartDate,
        public string $horizonEndDate,
        public string $runType = 'regenerative',
        public ?string $executedBy = null
    ) {}

    /**
     * One MRP run per tenant at a time: the tenant id is the uniqueness key of the queued job.
     */
    public function uniqueId(): string
    {
        return $this->tenantId;
    }

    /**
     * Runs the MRP for the tenant through the MRP service, which persists planned orders and pegging.
     */
    public function handle(MrpServiceInterface $mrpService): void
    {
        $dto = new RunMrpDTO(
            tenantId: $this->tenantId,
            horizonStartDate: $this->horizonStartDate,
            horizonEndDate: $this->horizonEndDate,
            runType: $this->runType,
            executedBy: $this->executedBy
        );

        $mrpService->executeMrpRun($dto);
    }

    /**
     * Logs the failed MRP run with its horizon and run type so it can be investigated and started again.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('MRP run failed.', [
            'tenant_id' => $this->tenantId,
            'horizon_start_date' => $this->horizonStartDate,
            'horizon_end_date' => $this->horizonEndDate,
            'run_type' => $this->runType,
            'error' => $exception?->getMessage(),
        ]);
    }
}
