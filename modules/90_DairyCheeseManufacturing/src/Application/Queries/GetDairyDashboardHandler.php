<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Application\Queries;

use Modules\DairyCheeseManufacturing\Services\DashboardService;

final class GetDairyDashboardHandler
{
    public function __construct(private readonly DashboardService $dashboard) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetDairyDashboardQuery $query): array
    {
        return $this->dashboard->build($query->date);
    }
}
