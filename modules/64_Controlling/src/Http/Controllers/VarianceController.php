<?php

declare(strict_types=1);

namespace Modules\Controlling\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Controlling\Http\Controllers\Concerns\InteractsWithControlling;
use Modules\Controlling\Http\Requests\VarianceQueryRequest;
use Modules\Controlling\Models\BudgetPlan;
use Modules\Controlling\Services\BudgetVarianceService;

/**
 * Variance analysis (actual, budget, rolling forecast, prior year) and the executive waterfall bridge.
 */
final class VarianceController extends Controller
{
    use InteractsWithControlling;

    public function __construct(private readonly BudgetVarianceService $budgets) {}

    public function variance(VarianceQueryRequest $request): JsonResponse
    {
        $this->authorize('viewAny', BudgetPlan::class);

        return response()->json($this->domain(fn (): array => $this->budgets->variance($this->tenantId(), $request->validated())));
    }

    public function waterfall(VarianceQueryRequest $request): JsonResponse
    {
        $this->authorize('viewAny', BudgetPlan::class);

        return response()->json(['data' => $this->domain(fn (): array => $this->budgets->waterfall($this->tenantId(), $request->validated()))]);
    }
}
