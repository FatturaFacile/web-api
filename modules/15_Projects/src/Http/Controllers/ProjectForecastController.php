<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Projects\Contracts\ProjectForecastServiceInterface;
use Modules\Projects\Models\Project;

final class ProjectForecastController extends Controller
{
    public function __construct(
        private readonly ProjectForecastServiceInterface $forecastService
    ) {}

    public function show(string $projectId): JsonResponse
    {
        $project = Project::with(['financialSummary', 'milestones', 'phases'])->findOrFail($projectId);

        $forecast = $this->forecastService->generateProjectForecast($project);

        return response()->json([
            'data' => $forecast,
        ]);
    }
}
