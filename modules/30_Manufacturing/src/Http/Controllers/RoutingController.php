<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Manufacturing\Contracts\RoutingServiceInterface;
use Modules\Manufacturing\DTOs\CreateRoutingDTO;
use Modules\Manufacturing\Http\Controllers\Concerns\InteractsWithManufacturing;
use Modules\Manufacturing\Http\Requests\CreateRoutingRequest;
use Modules\Manufacturing\Http\Resources\MfgRoutingResource;
use Modules\Manufacturing\Models\MfgRouting;
use OpenApi\Attributes as OA;

/**
 * Routings: operations on work centres with setup, run and labour times, optionally subcontracted.
 */
final class RoutingController extends Controller
{
    use InteractsWithManufacturing;

    public function __construct(private readonly RoutingServiceInterface $routings) {}

    /**
     * Paginated routings.
     */
    #[OA\Get(path: '/api/v1/manufacturing/routings', operationId: 'listMfgRoutings', description: 'Paginated routings of the active company, filtered by item.', summary: 'List routings', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Routings'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgItemFilter'), new OA\Parameter(ref: '#/components/parameters/MfgPage'), new OA\Parameter(ref: '#/components/parameters/MfgPerPage')],
        responses: [new OA\Response(response: 200, description: 'Routings', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MfgRouting'))])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401)])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = MfgRouting::query()->with('operations')->where('tenant_id', $this->tenantId());
        if ($request->filled('mfg_item_id')) {
            $query->where('mfg_item_id', (string) $request->query('mfg_item_id'));
        }

        return MfgRoutingResource::collection($query->orderBy('routing_number')->orderBy('revision')->paginate($this->perPage($request)));
    }

    /**
     * Creates a routing.
     */
    #[OA\Post(path: '/api/v1/manufacturing/routings', operationId: 'storeMfgRouting', description: 'Creates an active routing with its operations; a default routing becomes the default of the item.', summary: 'Create routing', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Routings'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MfgCreateRoutingRequest')),
        responses: [new OA\Response(response: 201, description: 'Routing created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgRouting')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function store(CreateRoutingRequest $request): JsonResponse
    {
        $data = $request->validated();
        $routing = $this->routings->createRouting(new CreateRoutingDTO(
            tenantId: $this->tenantId(),
            mfgItemId: (string) $data['mfg_item_id'],
            name: (string) $data['name'],
            operations: array_values($data['operations']),
            revision: (string) ($data['revision'] ?? 'A'),
            isDefault: (bool) ($data['is_default'] ?? true),
            effectiveFrom: $data['effective_from'] ?? null,
            effectiveTo: $data['effective_to'] ?? null,
            notes: $data['notes'] ?? null,
        ));

        return (new MfgRoutingResource($routing))->response()->setStatusCode(201);
    }

    /**
     * One routing with its operations.
     */
    #[OA\Get(path: '/api/v1/manufacturing/routings/{id}', operationId: 'showMfgRouting', description: 'Returns a routing of the active company with its operations.', summary: 'Get routing', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Routings'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        responses: [new OA\Response(response: 200, description: 'Routing', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgRouting')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404)])]
    public function show(string $id): MfgRoutingResource
    {
        return new MfgRoutingResource($this->owned(MfgRouting::class, $id)->load('operations'));
    }
}
