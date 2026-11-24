<?php

declare(strict_types=1);

namespace Modules\HumanResources\Actions;

use Modules\HumanResources\Contracts\EmployeeLifecycleServiceInterface;
use Modules\HumanResources\DTOs\PromoteEmployeeDTO;
use Modules\HumanResources\Models\EmployeeLifecycleEvent;

final readonly class PromoteEmployeeAction
{
    public function __construct(
        private EmployeeLifecycleServiceInterface $lifecycleService
    ) {}

    public function execute(PromoteEmployeeDTO $dto, ?string $actorUserId = null): EmployeeLifecycleEvent
    {
        return $this->lifecycleService->promoteEmployee($dto, $actorUserId);
    }
}
