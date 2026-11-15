<?php

declare(strict_types=1);

namespace Modules\Wms\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Wms\Http\Controllers\Concerns\InteractsWithWms;
use Modules\Wms\Http\Resources\SlottingRecommendationResource;
use Modules\Wms\Models\WmsSlottingRecommendation;
use Modules\Wms\Services\SlottingService;
use OpenApi\Attributes as OA;

/**
 * Slotting recommendations: generate them from the pick velocity, accept (creates a move task) or reject.
 */
final class SlottingActionController extends Controller
{
    use InteractsWithWms;

    public function __construct(private readonly SlottingService $slotting) {}

    #[OA\Post(path: '/api/v1/wms/slotting-recommendations/generate', operationId: 'generateWmsSlottingRecommendations', description: 'Ranks the items of a warehouse by pick velocity over the period (picks per day from the stock movements), classifies them A (first 80 percent of the picks), B (up to 95) and C, matches the active slotting rules (default: A picking, B storage, C bulk) and proposes a move to a free, unlocked bin of the right type for every item that is stored elsewhere. Pending recommendations of the warehouse are replaced.', summary: 'Generate slotting recommendations', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['warehouse_id'], properties: [new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'), new OA\Property(property: 'period_days', type: 'integer', example: 30)])), responses: [
        new OA\Response(response: 200, description: 'Summary and the new recommendations', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'summary', type: 'object'), new OA\Property(property: 'recommendations', type: 'array', items: new OA\Items(ref: '#/components/schemas/WmsSlottingRecommendation'))])])),
        new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
    ])]
    public function generate(Request $request): JsonResponse
    {
        $this->authorize('create', WmsSlottingRecommendation::class);
        $data = $request->validate([
            'warehouse_id' => ['required', 'uuid', Rule::exists('warehouses', 'id')->where('tenant_id', $this->tenantId())->whereNull('deleted_at')],
            'period_days' => ['nullable', 'integer', 'min:7', 'max:365'],
        ]);

        $result = $this->slotting->generate($this->tenantId(), (string) $data['warehouse_id'], (int) ($data['period_days'] ?? 30));

        return response()->json(['data' => ['summary' => $result['summary'], 'recommendations' => SlottingRecommendationResource::collection($result['recommendations'])->resolve()]]);
    }

    #[OA\Post(path: '/api/v1/wms/slotting-recommendations/{id}/accept', operationId: 'acceptWmsSlottingRecommendation', description: 'Creates a move task that carries the whole stock of the item from its bin to the recommended bin, and marks the recommendation accepted. Refused when the stock moved or the bin got locked meanwhile.', summary: 'Accept a recommendation', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsSlottingRecommendation')])),
        new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
    ])]
    public function accept(string $id): SlottingRecommendationResource
    {
        $record = WmsSlottingRecommendation::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new SlottingRecommendationResource($this->slotting->accept($record));
    }

    #[OA\Post(path: '/api/v1/wms/slotting-recommendations/{id}/reject', operationId: 'rejectWmsSlottingRecommendation', description: 'Marks a pending recommendation rejected.', summary: 'Reject a recommendation', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsSlottingRecommendation')])),
        new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
    ])]
    public function reject(string $id): SlottingRecommendationResource
    {
        $record = WmsSlottingRecommendation::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new SlottingRecommendationResource($this->slotting->reject($record));
    }
}
