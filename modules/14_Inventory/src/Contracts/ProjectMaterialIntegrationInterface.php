<?php

declare(strict_types=1);

namespace Modules\Inventory\Contracts;

/**
 * Interface prepared for Module 15 Projects & Job Costing integration.
 * Dedicated to allocating, staging, and consuming inventory materials against project WBS tasks.
 */
interface ProjectMaterialIntegrationInterface
{
    public function reserveProjectMaterials(string $projectId, string $projectTaskId, string $stockItemId, float $quantity): string;

    public function issueMaterialsToJob(string $reservationId, string $destinationLocationId, string $userId): string;

    public function returnUnusedMaterialFromJob(string $projectId, string $stockItemId, string $warehouseBinId, float $quantity): string;
}
