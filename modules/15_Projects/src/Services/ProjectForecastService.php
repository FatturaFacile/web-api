<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Modules\Projects\Contracts\ProjectForecastServiceInterface;
use Modules\Projects\Models\Project;

/**
 * Forecast to completion of a project. It is a read: it computes the figures and publishes nothing, so read-only users
 * can consult it without writing to the event outbox.
 */
final readonly class ProjectForecastService implements ProjectForecastServiceInterface
{
    public function __construct(private ProjectProfitabilityService $profitability) {}

    /**
     * @return array<string, mixed>
     */
    public function generateProjectForecast(Project $project): array
    {
        return $this->profitability->forecast($project);
    }
}
