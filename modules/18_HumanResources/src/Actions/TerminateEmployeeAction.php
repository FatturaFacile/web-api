<?php

declare(strict_types=1);

namespace Modules\HumanResources\Actions;

use Modules\HumanResources\Contracts\EmployeeLifecycleServiceInterface;
use Modules\HumanResources\DTOs\TerminateEmployeeDTO;
use Modules\HumanResources\Models\Employee;

final readonly class TerminateEmployeeAction
{
    public function __construct(
        private EmployeeLifecycleServiceInterface $lifecycleService
    ) {}

    public function execute(TerminateEmployeeDTO $dto, ?string $actorUserId = null): Employee
    {
        return $this->lifecycleService->terminateEmployee($dto, $actorUserId);
    }
}
