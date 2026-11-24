<?php

declare(strict_types=1);

namespace Modules\Sustainability\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Sustainability\Http\Controllers\Concerns\InteractsWithSustainability;
use Modules\Sustainability\Models\EsgReportingPeriod;
use Modules\Sustainability\Services\Scope3Service;
use OpenApi\Attributes as OA;

/**
 * Scope 3 emissions of a period by the 15 GHG Protocol categories.
 */
final class Scope3Controller extends Controller
{
    use InteractsWithSustainability;

    public function __construct(private readonly Scope3Service $scope3) {}

    #[OA\Get(path: '/api/v1/sustainability/periods/{id}/scope3', operationId: 'showEsgScope3', description: 'Scope 3 emissions of the period for each of the 15 GHG Protocol categories with the share of supplier-specific and estimated data and the open supplier data requests.', summary: 'Scope 3 by category', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], parameters: [new OA\Parameter(ref: '#/components/parameters/SustainabilityId')], responses: [
        new OA\Response(response: 200, description: 'Scope 3 report', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilityScope3Report')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityNotFound', response: 404),
    ])]
    public function show(string $id): JsonResponse
    {
        $period = EsgReportingPeriod::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('view', $period);

        return response()->json(['data' => $this->scope3->report($period)]);
    }
}
