<?php

declare(strict_types=1);

namespace Modules\Contracts\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Contracts\Services\ContractDeadlineService;

final class ProcessContractDeadlinesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $tenantId)
    {
        $this->afterCommit();
    }

    public function handle(ContractDeadlineService $deadlines): void
    {
        $deadlines->run($this->tenantId, CarbonImmutable::today());
    }
}
