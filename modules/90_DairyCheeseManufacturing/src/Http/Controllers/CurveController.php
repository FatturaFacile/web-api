<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\DairyCheeseManufacturing\Exceptions\DairyCheeseException;
use Modules\DairyCheeseManufacturing\Http\Requests\SaveCurveRequest;
use Modules\DairyCheeseManufacturing\Http\Resources\CurveResource;
use Modules\DairyCheeseManufacturing\Models\WeightLossCurve;
use Modules\DairyCheeseManufacturing\Services\WheelMetrics;
use OpenApi\Attributes as OA;

/**
 * Physiological weight loss curves by cheese type and age (shared templates are read-only).
 */
final class CurveController extends Controller
{
    use ApiResponse;
    use DairyControllerSupport;

    public function __construct(private readonly WheelMetrics $metrics) {}

    #[OA\Get(path: '/api/v1/dairy-cheese/weight-loss-curves', operationId: 'dairyCheeseCurvesList', summary: 'List the weight loss curves (own and shared templates)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], responses: [new OA\Response(response: 200, description: 'Curves')])]
    public function index(): JsonResponse
    {
        $this->view();

        return $this->successResponse(CurveResource::collection(WeightLossCurve::query()->orderBy('cheese_type')->orderBy('name')->get()));
    }

    #[OA\Post(path: '/api/v1/dairy-cheese/weight-loss-curves', operationId: 'dairyCheeseCurvesCreate', summary: 'Create a weight loss curve', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], responses: [new OA\Response(response: 201, description: 'Curve created')])]
    public function store(SaveCurveRequest $request): JsonResponse
    {
        $curve = WeightLossCurve::query()->create($this->attributes($request) + ['created_by' => $this->userId($request)]);
        $this->metrics->flush();

        return $this->successResponse(new CurveResource($curve), $this->message('saved'), 201);
    }

    #[OA\Put(path: '/api/v1/dairy-cheese/weight-loss-curves/{id}', operationId: 'dairyCheeseCurvesUpdate', summary: 'Update a weight loss curve', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Curve updated')])]
    public function update(SaveCurveRequest $request, string $id): JsonResponse
    {
        $curve = WeightLossCurve::query()->findOrFail($id);

        if ($curve->tenant_id === null) {
            throw new DairyCheeseException('Shared curves cannot be changed: create your own curve.', 'template_read_only', 403);
        }

        $curve->fill($this->attributes($request) + ['updated_by' => $this->userId($request)])->save();
        $this->metrics->flush();

        return $this->successResponse(new CurveResource($curve), $this->message('saved'));
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(SaveCurveRequest $request): array
    {
        $data = $request->validated();
        $points = array_map(static fn (array $p): array => ['age_months' => (float) $p['age_months'], 'loss_pct' => (float) $p['loss_pct']], $data['points']);
        usort($points, static fn (array $a, array $b): int => $a['age_months'] <=> $b['age_months']);
        $data['points'] = $points;

        return $data;
    }
}
