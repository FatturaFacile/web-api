<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Projects\Contracts\ProjectRevenueRecognitionServiceInterface;
use Modules\Projects\DTOs\RevenueRecognitionDTO;
use Modules\Projects\Http\Requests\StoreRevenueRecognitionRequest;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectRevenueRecognition;

final class ProjectRevenueRecognitionController extends Controller
{
    public function __construct(
        private readonly ProjectRevenueRecognitionServiceInterface $revenueService
    ) {}

    public function index(string $projectId, Request $request): JsonResponse
    {
        $project = Project::findOrFail($projectId);

        $recognitions = ProjectRevenueRecognition::where('project_id', $project->id)
            ->orderBy('accounting_period_date', 'desc')
            ->paginate((int) $request->query('per_page', 15));

        return response()->json([
            'data' => $recognitions->items(),
            'meta' => [
                'current_page' => $recognitions->currentPage(),
                'last_page' => $recognitions->lastPage(),
                'total' => $recognitions->total(),
            ],
        ]);
    }

    public function store(string $projectId, StoreRevenueRecognitionRequest $request): JsonResponse
    {
        $project = Project::findOrFail($projectId);

        $dto = new RevenueRecognitionDTO(
            projectId: $project->id,
            accountingPeriodDate: $request->validated('accounting_period_date'),
            overrideCompletionPercentage: $request->validated('override_completion_percentage') !== null
                ? (float) $request->validated('override_completion_percentage')
                : null,
            userId: (string) auth()->id()
        );

        $recognition = $this->revenueService->calculateAndRecognize($dto);

        return response()->json([
            'message' => 'Revenue recognized and ledger/billing events dispatched',
            'data' => [
                'id' => $recognition->id,
                'project_id' => $recognition->project_id,
                'accounting_period_date' => $recognition->accounting_period_date?->toDateString(),
                'recognition_method' => $recognition->recognition_method,
                'cost_completion_percentage' => $recognition->cost_completion_percentage,
                'cumulative_revenue' => $recognition->cumulative_recognized_revenue,
                'period_revenue_adjustment' => $recognition->period_revenue_adjustment,
                'wip_balance' => $recognition->wip_balance,
                'unbilled_receivables' => $recognition->unbilled_receivables,
                'deferred_revenue' => $recognition->deferred_revenue,
            ],
        ], 201);
    }
}
