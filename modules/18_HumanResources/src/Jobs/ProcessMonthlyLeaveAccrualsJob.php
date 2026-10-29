<?php

declare(strict_types=1);

namespace Modules\HumanResources\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\HumanResources\Contracts\LeaveAndTimeServiceInterface;

final class ProcessMonthlyLeaveAccrualsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $afterCommit = true;

    public function __construct(
        public readonly string $tenantId,
        public readonly int $fiscalYear
    ) {}

    public function handle(LeaveAndTimeServiceInterface $leaveService): void
    {
        $leaveService->runMonthlyAccruals($this->tenantId, $this->fiscalYear);
    }
}
