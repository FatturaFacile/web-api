<?php

declare(strict_types=1);

namespace Modules\Projects\Actions;

use Modules\Projects\Contracts\JobCostingAccumulationServiceInterface;
use Modules\Projects\DTOs\RecordJobCostDTO;
use Modules\Projects\Models\ProjectCostEntry;

final readonly class RecordJobCostEntryAction
{
    public function __construct(
        private JobCostingAccumulationServiceInterface $costingService
    ) {}

    public function execute(RecordJobCostDTO $dto): ProjectCostEntry
    {
        return $this->costingService->recordCost($dto);
    }
}
