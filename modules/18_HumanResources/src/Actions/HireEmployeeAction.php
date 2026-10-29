<?php

declare(strict_types=1);

namespace Modules\HumanResources\Actions;

use Modules\HumanResources\Contracts\EmployeeLifecycleServiceInterface;
use Modules\HumanResources\DTOs\HireEmployeeDTO;
use Modules\HumanResources\Models\Employee;

final readonly class HireEmployeeAction
{
    public function __construct(
        private EmployeeLifecycleServiceInterface $lifecycleService
    ) {}

    public function execute(HireEmployeeDTO $dto, ?string $actorUserId = null): Employee
    {
        return $this->lifecycleService->hireEmployee($dto, $actorUserId);
    }
}
