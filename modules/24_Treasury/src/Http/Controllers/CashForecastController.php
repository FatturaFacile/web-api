<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Treasury\Actions\GenerateCashForecastAction;
use Modules\Treasury\Contracts\CashForecastServiceInterface;
use Modules\Treasury\Http\Requests\GenerateForecastRequest;
use Modules\Treasury\Http\Resources\CashForecastResource;
use Modules\Treasury\Models\CashForecast;

final class CashForecastController extends Controller
{
    public function __construct(
        private readonly CashForecastServiceInterface $forecastService,
        private readonly GenerateCashForecastAction $generateAction
    ) {}

    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $forecasts = CashForecast::where('tenant_id', $tenantId)
            ->latest('calculation_date')
            ->paginate((int) $request->query('per_page', 25));

        return response()->json(CashForecastResource::collection($forecasts)->response()->getData(true));
    }

    public function generate(GenerateForecastRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $scenario = $request->input('scenario_code', 'BASELINE');
        $horizon = (int) $request->input('horizon_days', 90);
        $date = $request->input('calculation_date', now()->toDateString());

        $forecast = $this->generateAction->execute($tenantId, $scenario, $horizon, $date);

        return response()->json(new CashForecastResource($forecast), 201);
    }

    public function show(string $id): JsonResponse
    {
        $forecast = CashForecast::with('lines')->findOrFail($id);

        return response()->json(new CashForecastResource($forecast));
    }

    public function evaluateDeficits(string $id): JsonResponse
    {
        $evaluation = $this->forecastService->evaluateDeficits($id);

        return response()->json($evaluation);
    }
}
