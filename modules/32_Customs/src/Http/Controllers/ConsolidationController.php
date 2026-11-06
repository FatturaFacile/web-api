<?php

declare(strict_types=1);

namespace Modules\Customs\Http\Controllers;

use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Customs\Exceptions\CustomsException;
use Modules\Customs\Http\Requests\SaveConsolidationRequest;
use Modules\Customs\Http\Resources\CustomsRecordResource;
use Modules\Customs\Models\CustomsConsolidation;
use Modules\Customs\Models\CustomsDossier;
use Modules\Customs\Services\DossierService;
use OpenApi\Attributes as OA;

/**
 * Groupage: dossiers travelling together.
 */
final class ConsolidationController extends CustomsController
{
    public function __construct(TenantContext $tenantContext, private readonly DossierService $dossiers)
    {
        parent::__construct($tenantContext);
    }

    /**
     * List consolidations.
     */
    #[OA\Get(path: '/api/v1/customs/consolidations', operationId: 'listCustomsConsolidations', description: 'Return the consolidations of the company with their dossiers.', summary: 'List consolidations', security: [['bearerAuth' => []]], tags: ['Customs'], responses: [
        new OA\Response(response: 200, description: 'Consolidations', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CustomsConsolidation::class);

        return CustomsRecordResource::collection(CustomsConsolidation::query()->with('dossiers')->latest()->paginate($this->perPage($request)));
    }

    /**
     * Create a consolidation.
     */
    #[OA\Post(path: '/api/v1/customs/consolidations', operationId: 'storeCustomsConsolidation', description: 'Create a consolidation with its freight cost.', summary: 'Create consolidation', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CustomsConsolidationRequest')), tags: ['Customs'], responses: [
        new OA\Response(response: 201, description: 'Consolidation created', content: new OA\JsonContent(ref: '#/components/schemas/CustomsRecordResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function store(SaveConsolidationRequest $request): JsonResponse
    {
        $this->authorize('create', CustomsConsolidation::class);

        return (new CustomsRecordResource(CustomsConsolidation::query()->create($request->validated())))->response()->setStatusCode(201);
    }

    /**
     * Add a dossier to a consolidation.
     */
    #[OA\Post(path: '/api/v1/customs/consolidations/{id}/dossiers/{dossierId}', operationId: 'attachCustomsConsolidationDossier', description: 'Put a dossier on a consolidation whose freight has not been shared out yet.', summary: 'Add dossier to consolidation', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId'), new OA\Parameter(name: 'dossierId', description: 'Dossier identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Dossier added', content: new OA\JsonContent(ref: '#/components/schemas/CustomsRecordResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function attach(string $id, string $dossierId): CustomsRecordResource
    {
        $consolidation = CustomsConsolidation::query()->findOrFail($id);
        $dossier = CustomsDossier::query()->findOrFail($dossierId);
        $this->authorize('update', $consolidation);
        $this->authorize('update', $dossier);

        if ($consolidation->allocated_at !== null) {
            throw new CustomsException('The freight of this consolidation has already been shared out.');
        }

        $dossier->update(['consolidation_id' => $consolidation->id]);

        return new CustomsRecordResource($dossier);
    }

    /**
     * Share out the freight.
     */
    #[OA\Post(path: '/api/v1/customs/consolidations/{id}/allocate', operationId: 'allocateCustomsConsolidation', description: 'Share the freight of a consolidation among its dossiers by weight, as direct costs.', summary: 'Share out consolidation freight', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 200, description: 'Shares', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function allocate(string $id): JsonResponse
    {
        $consolidation = CustomsConsolidation::query()->findOrFail($id);
        $this->authorize('update', $consolidation);

        return response()->json(['data' => $this->dossiers->allocateConsolidation($consolidation)]);
    }
}
