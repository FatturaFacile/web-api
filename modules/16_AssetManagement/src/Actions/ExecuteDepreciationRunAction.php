<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Actions;

use Modules\AssetManagement\Contracts\DepreciationEngineServiceInterface;
use Modules\AssetManagement\DTOs\RunDepreciationDTO;
use Modules\AssetManagement\Models\DepreciationRun;

final readonly class ExecuteDepreciationRunAction
{
    public function __construct(
        private DepreciationEngineServiceInterface $depreciationEngine
    ) {}

    public function execute(RunDepreciationDTO $dto, ?string $actorUserId = null): DepreciationRun
    {
        return $this->depreciationEngine->executeDepreciationRun($dto, $actorUserId);
    }
}
