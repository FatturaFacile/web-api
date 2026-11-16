<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ConstructionSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Modules\ConstructionTrades\Http\Requests\SaveConstructionSiteRequest;
use OpenApi\Attributes as OA;

/**
 * Writes the construction sites (cantieri) that progress billings (SAL) and bids refer to. The list stays on
 * GET /api/v1/construction/sites.
 */
final class ConstructionSiteController extends Controller
{
    /**
     * Open a construction site of the active company for a customer. The code is unique in the company.
     */
    #[OA\Post(path: '/api/v1/construction/sites', operationId: 'storeConstructionSite', description: 'Open a construction site of the active company for a customer. The code is unique in the company.', summary: 'Create construction site', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ConstructionSiteRequest')), tags: ['Construction'], responses: [
        new OA\Response(response: 201, description: 'Site created', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordResponse')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesUnprocessable', response: 422),
    ])]
    public function store(SaveConstructionSiteRequest $request): JsonResponse
    {
        $this->authorize('create', ConstructionSite::class);

        $site = ConstructionSite::create(['status' => 'planning', ...$request->validated()]);

        return (new JsonResource($site))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Return a construction site with its customer.
     */
    #[OA\Get(path: '/api/v1/construction/sites/{id}', operationId: 'showConstructionSite', description: 'Return a construction site with its customer.', summary: 'Show construction site', security: [['bearerAuth' => []]], tags: ['Construction'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId')], responses: [
        new OA\Response(response: 200, description: 'Site', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordResponse')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
    ])]
    public function show(string $id): JsonResource
    {
        return new JsonResource($this->find($id, 'view')->load('client'));
    }

    /**
     * Change a construction site, its dates or its status (planning, active, paused, completed).
     */
    #[OA\Put(path: '/api/v1/construction/sites/{id}', operationId: 'updateConstructionSite', description: 'Change a construction site, its dates or its status (planning, active, paused, completed).', summary: 'Change construction site', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ConstructionSiteRequest')), tags: ['Construction'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId')], responses: [
        new OA\Response(response: 200, description: 'Site changed', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordResponse')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TradesUnprocessable', response: 422),
    ])]
    #[OA\Patch(path: '/api/v1/construction/sites/{id}', operationId: 'patchConstructionSite', description: 'Change some fields of a construction site.', summary: 'Partially change construction site', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ConstructionSiteRequest')), tags: ['Construction'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId')], responses: [
        new OA\Response(response: 200, description: 'Site changed', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordResponse')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TradesUnprocessable', response: 422),
    ])]
    public function update(SaveConstructionSiteRequest $request, string $id): JsonResource
    {
        $site = $this->find($id, 'update');
        $site->update($request->validated());

        return new JsonResource($site);
    }

    /**
     * Archive a construction site (soft delete). Refused while it has progress billings: complete it instead.
     */
    #[OA\Delete(path: '/api/v1/construction/sites/{id}', operationId: 'destroyConstructionSite', description: 'Archive a construction site (soft delete). Refused while it has progress billings: complete it instead.', summary: 'Archive construction site', security: [['bearerAuth' => []]], tags: ['Construction'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId')], responses: [
        new OA\Response(response: 204, description: 'Site archived'),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TradesUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $site = $this->find($id, 'delete');

        if ($site->progressBillings()->exists()) {
            throw ValidationException::withMessages(['site' => __('construction_trades_errors.site.has_progress_billings')]);
        }

        $site->delete();

        return response()->noContent();
    }

    private function find(string $id, string $ability): ConstructionSite
    {
        $site = ConstructionSite::query()->findOrFail($id);
        $this->authorize($ability, $site);

        return $site;
    }
}
