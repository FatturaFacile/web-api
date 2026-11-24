<?php

declare(strict_types=1);

namespace Modules\HumanResources\Contracts;

use Modules\HumanResources\DTOs\HireEmployeeDTO;
use Modules\HumanResources\DTOs\PromoteEmployeeDTO;
use Modules\HumanResources\DTOs\TerminateEmployeeDTO;
use Modules\HumanResources\DTOs\TransferEmployeeDTO;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\EmployeeLifecycleEvent;

interface EmployeeLifecycleServiceInterface
{
    public function hireEmployee(HireEmployeeDTO $dto, ?string $actorUserId = null): Employee;

    public function completeProbation(Employee $employee, ?string $confirmationDate = null, ?string $actorUserId = null): Employee;

    public function promoteEmployee(PromoteEmployeeDTO $dto, ?string $actorUserId = null): EmployeeLifecycleEvent;

    public function transferEmployee(TransferEmployeeDTO $dto, ?string $actorUserId = null): EmployeeLifecycleEvent;

    public function terminateEmployee(TerminateEmployeeDTO $dto, ?string $actorUserId = null): Employee;

    public function rehireEmployee(Employee $employee, float $newSalary, ?string $rehireDate = null, ?string $actorUserId = null): Employee;

    public function recordAssetAssignment(Employee $employee, string $assetId, string $assetAction, array $assetDetails = []): Employee;
}
