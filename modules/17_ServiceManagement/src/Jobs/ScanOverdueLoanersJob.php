<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\ServiceManagement\Services\LoanerService;

final class ScanOverdueLoanersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly ?string $tenantId = null,
        public readonly ?int $graceDays = null,
    ) {
        $this->afterCommit();
    }

    public function handle(LoanerService $loanerService): void
    {
        $loanerService->scanOverdue($this->tenantId, $this->graceDays);
    }
}
