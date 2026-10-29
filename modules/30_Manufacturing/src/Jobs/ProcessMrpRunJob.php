<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Manufacturing\Contracts\MrpServiceInterface;
use Modules\Manufacturing\DTOs\RunMrpDTO;

final class ProcessMrpRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $tenantId,
        public string $horizonStartDate,
        public string $horizonEndDate,
        public string $runType = 'regenerative',
        public ?string $executedBy = null
    ) {}

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
}
