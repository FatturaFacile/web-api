<?php

declare(strict_types=1);

namespace Modules\HumanResources\Contracts;

use Modules\HumanResources\Models\Position;

interface OrganizationManagementServiceInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildOrgChart(string $tenantId): array;

    public function assignSuccessor(Position $position, string $successorEmployeeId, string $readinessLevel = 'ready_1_2_years', ?string $actorUserId = null): Position;

    /**
     * @return array<string, mixed>
     */
    public function generatePeriodCostAndPayrollForecast(string $tenantId, string $periodCode): array;
}
