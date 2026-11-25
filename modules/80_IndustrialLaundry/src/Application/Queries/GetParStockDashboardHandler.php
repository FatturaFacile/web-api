<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Queries;

use Illuminate\Support\Carbon;
use Modules\IndustrialLaundry\Services\ParStockService;

final class GetParStockDashboardHandler
{
    public function __construct(private readonly ParStockService $parStock) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(GetParStockDashboardQuery $query): array
    {
        return $this->parStock->overview($query->segment, $query->customerId, $query->asOf === null ? null : Carbon::parse($query->asOf), $query->withPreview);
    }
}
