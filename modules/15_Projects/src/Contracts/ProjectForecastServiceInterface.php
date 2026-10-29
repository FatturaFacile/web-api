<?php

declare(strict_types=1);

namespace Modules\Projects\Contracts;

use Modules\Projects\Models\Project;

interface ProjectForecastServiceInterface
{
    /**
     * @return array{
     *     project_id: string,
     *     project_number: string,
     *     bac: float,
     *     ac: float,
     *     cpi: float,
     *     spi: float,
     *     eac: float,
     *     etc: float,
     *     vac: float,
     *     projected_margin_amount: float,
     *     projected_margin_percentage: float,
     *     cash_inflows: array<int, array{milestone_name: string, date: string, amount: float}>,
     *     cash_outflows: array<int, array{category: string, date: string, amount: float}>,
     *     net_cash_flow: float
     * }
     */
    public function generateProjectForecast(Project $project): array;
}
