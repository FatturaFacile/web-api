<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CRM\Contracts\ForecastServiceInterface;
use Modules\CRM\DTOs\GenerateForecastDTO;
use Modules\CRM\Http\Requests\GenerateForecastRequest;
use Modules\CRM\Http\Resources\ForecastResource;
use Modules\CRM\Models\Forecast;

final class ForecastController extends Controller
{
    public function __construct(
        private readonly ForecastServiceInterface $forecastService,
        private readonly TenantContext $tenantContext
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $query = Forecast::where('tenant_id', $tenant->id);

        if ($request->has('period')) {
            $query->where('period', $request->query('period'));
        }

        $forecasts = $query->orderByDesc('period')->paginate(25);

        return response()->json([
            'data' => ForecastResource::collection($forecasts),
            'meta' => [
                'current_page' => $forecasts->currentPage(),
                'total' => $forecasts->total(),
            ],
        ]);
    }

    public function generate(GenerateForecastRequest $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $actorId = (string) ($request->user()?->id ?? 'system');

        $forecast = $this->forecastService->generateForecast(new GenerateForecastDTO(
            tenantId: (string) $tenant->id,
            period: $request->input('period'),
            userId: $request->input('user_id'),
            currency: $request->input('currency', 'EUR'),
            quotaAmount: (float) $request->input('quota_amount', 0.0)
        ), $actorId);

        return response()->json(new ForecastResource($forecast), 201);
    }

    public function lock(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $forecast = Forecast::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? 'system');

        $locked = $this->forecastService->lockForecast($forecast, $actorId);

        return response()->json(new ForecastResource($locked));
    }
}
