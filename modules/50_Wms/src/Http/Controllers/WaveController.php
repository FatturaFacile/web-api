<?php

declare(strict_types=1);

namespace Modules\Wms\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Modules\Wms\Http\Controllers\Concerns\InteractsWithWms;
use Modules\Wms\Http\Resources\WaveResource;
use Modules\Wms\Models\WmsWave;
use Modules\Wms\Services\WaveService;
use OpenApi\Attributes as OA;

/**
 * Waves of the active company.
 */
final class WaveController extends Controller
{
    use InteractsWithWms;

    public function __construct(private readonly WaveService $service) {}

    #[OA\Get(path: '/api/v1/wms/waves', operationId: 'listWmsWaves', description: 'Waves of the active company. Filters: warehouse_id, status.', summary: 'List waves', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['planned', 'released', 'completed', 'cancelled']))], responses: [
        new OA\Response(response: 200, description: 'Waves', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WmsWave'))])),
        new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', WmsWave::class);
        $filters = $request->validate(['warehouse_id' => ['nullable', 'uuid'], 'status' => ['nullable', Rule::in(WmsWave::Statuses)]]);

        return WaveResource::collection(
            WmsWave::query()
                ->with(['warehouse:id,code,name'])
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['warehouse_id']), static fn ($query) => $query->where('warehouse_id', $filters['warehouse_id']))
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->orderByDesc('created_at')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Get(path: '/api/v1/wms/waves/{id}', operationId: 'showWmsWave', description: 'Return a wave.', summary: 'Show a wave', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], responses: [
        new OA\Response(response: 200, description: 'Wave', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsWave')])),
        new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
    ])]
    public function show(string $id): WaveResource
    {
        $record = WmsWave::query()->with(['warehouse:id,code,name'])->findOrFail($id);
        $this->authorize('view', $record);

        return new WaveResource($record);
    }
}
