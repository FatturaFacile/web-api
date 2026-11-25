<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Application\Queries;

use Illuminate\Support\Carbon;
use Modules\DebtCollection\Services\DashboardService;

final class GetDashboardHandler
{
    public function __construct(
        private DashboardService $dashboard,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetDashboardQuery $query): array
    {
        return $this->dashboard->summary($query->tenantId, Carbon::parse($query->asOf));
    }
}
