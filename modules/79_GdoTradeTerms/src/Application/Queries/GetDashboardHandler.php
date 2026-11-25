<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Queries;

use Illuminate\Support\Carbon;
use Modules\GdoTradeTerms\Services\DashboardService;

final class GetDashboardHandler
{
    public function __construct(private readonly DashboardService $dashboard) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetDashboardQuery $query): array
    {
        return $this->dashboard->summary($query->tenantId, $query->asOf === null ? Carbon::today() : Carbon::parse($query->asOf));
    }
}
