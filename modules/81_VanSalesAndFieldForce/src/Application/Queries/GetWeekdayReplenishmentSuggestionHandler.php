<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Application\Queries;

use Modules\VanSalesAndFieldForce\Models\VanWarehouse;
use Modules\VanSalesAndFieldForce\Services\VanLoadManifestService;

final readonly class GetWeekdayReplenishmentSuggestionHandler
{
    public function __construct(private VanLoadManifestService $service) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(GetWeekdayReplenishmentSuggestionQuery $query): array
    {
        $van = VanWarehouse::query()
            ->where('tenant_id', $query->tenantId)
            ->findOrFail($query->vanWarehouseId);

        return $this->service->suggestReplenishment(
            $query->tenantId,
            $van,
            $query->weekdayIso,
            $query->routeCode,
        );
    }
}
