<?php

declare(strict_types=1);

namespace Modules\Projects\Contracts;

use Modules\Projects\Models\Project;

/**
 * Forecast to completion of a project (EVM estimate, remaining budget, expected billing and outflows).
 */
interface ProjectForecastServiceInterface
{
    /**
     * @return array<string, mixed>
     */
    public function generateProjectForecast(Project $project): array;
}
