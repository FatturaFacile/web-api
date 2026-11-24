<?php

declare(strict_types=1);

namespace Modules\Procurement\Actions;

use Modules\Procurement\Contracts\ThreeWayMatchingServiceInterface;
use Modules\Procurement\DTOs\ExecuteThreeWayMatchDTO;
use Modules\Procurement\Models\ThreeWayMatchLog;

final class ExecuteThreeWayMatchAction
{
    public function __construct(
        private readonly ThreeWayMatchingServiceInterface $matchingService
    ) {}

    public function execute(ExecuteThreeWayMatchDTO $dto): ThreeWayMatchLog
    {
        return $this->matchingService->executeMatch($dto);
    }
}
