<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\AssetManagement\Contracts\DepreciationEngineServiceInterface;
use Modules\AssetManagement\DTOs\RunDepreciationDTO;

final class ExecuteQuarterlyDepreciationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $periodCode,
        public readonly string $ledgerType = 'statutory',
        public readonly ?string $actorUserId = null
    ) {
        $this->afterCommit();
    }

    public function handle(DepreciationEngineServiceInterface $depreciationEngine): void
    {
        $depreciationEngine->executeDepreciationRun(new RunDepreciationDTO(
            tenantId: $this->tenantId,
            companyId: $this->companyId,
            periodCode: $this->periodCode,
            periodType: 'quarterly',
            ledgerType: $this->ledgerType,
            autoPostToGl: true
        ), $this->actorUserId);
    }
}
