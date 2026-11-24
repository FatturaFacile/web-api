<?php

declare(strict_types=1);

namespace Modules\Workshop\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Workshop\Http\Requests\PartConsumptionRequest;
use Modules\Workshop\Http\Resources\WorkshopResource;
use Modules\Workshop\Models\WorkshopFolder;
use Modules\Workshop\Models\WorkshopPartConsumption;
use Modules\Workshop\Services\PartConsumptionService;
use OpenApi\Attributes as OA;

/**
 * Parts a folder takes from stock: reserve, issue when fitted, release when unused. Stock is reached through the stock
 * gateway of the workshop, never through the inventory tables.
 */
final class PartConsumptionController extends Controller
{
    public function __construct(private readonly PartConsumptionService $parts) {}

    /**
     * Return the parts reserved, issued or released on a folder.
     */
    #[OA\Get(path: '/api/v1/workshop/folders/{id}/parts', operationId: 'listWorkshopFolderParts', description: 'Return the parts a folder reserved, issued or released, oldest first.', summary: 'List folder parts', security: [['bearerAuth' => []]], tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 200, description: 'Parts', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordCollection')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
    ])]
    public function index(string $id): AnonymousResourceCollection
    {
        return WorkshopResource::collection($this->parts->forFolder($this->folder($id, 'view')));
    }

    /**
     * Return the stock a product can still be promised.
     */
    #[OA\Get(path: '/api/v1/workshop/parts/{id}/availability', operationId: 'workshopPartAvailability', description: 'Return whether a product is stock-tracked and the quantity the default warehouse can still promise.', summary: 'Part availability', security: [['bearerAuth' => []]], tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 200, description: 'Availability', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopPartAvailabilityResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
    ])]
    public function availability(string $id): JsonResponse
    {
        $this->authorize('viewAny', WorkshopPartConsumption::class);

        return response()->json(['data' => $this->parts->availability($id)]);
    }

    /**
     * Reserve a part of stock for a folder.
     */
    #[OA\Post(path: '/api/v1/workshop/folders/{id}/parts', operationId: 'reserveWorkshopFolderPart', description: 'Set a quantity of a stock-tracked product aside for a folder that is open or in progress. The reservation is made in the inventory module.', summary: 'Reserve part', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkshopPartRequest')), tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 201, description: 'Part reserved', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function reserve(PartConsumptionRequest $request, string $id): JsonResponse
    {
        $consumption = $this->parts->reserve($this->folder($id, 'update'), $request->validated());

        return (new WorkshopResource($consumption))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Issue a reserved part out of stock.
     */
    #[OA\Post(path: '/api/v1/workshop/part-consumptions/{id}/issue', operationId: 'issueWorkshopPart', description: 'Issue a reserved part out of stock: the reservation is released and the stock movement is written.', summary: 'Issue part', security: [['bearerAuth' => []]], tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 200, description: 'Part issued', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function issue(string $id): WorkshopResource
    {
        return new WorkshopResource($this->parts->issue($this->consumption($id)));
    }

    /**
     * Give a reserved part back to stock.
     */
    #[OA\Post(path: '/api/v1/workshop/part-consumptions/{id}/release', operationId: 'releaseWorkshopPart', description: 'Release the reservation of a part that is not used.', summary: 'Release part', security: [['bearerAuth' => []]], tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 200, description: 'Part released', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function release(string $id): WorkshopResource
    {
        return new WorkshopResource($this->parts->release($this->consumption($id)));
    }

    private function folder(string $id, string $ability): WorkshopFolder
    {
        $folder = WorkshopFolder::query()->findOrFail($id);
        $this->authorize($ability, $folder);

        return $folder;
    }

    private function consumption(string $id): WorkshopPartConsumption
    {
        $consumption = WorkshopPartConsumption::query()->findOrFail($id);
        $this->authorize('update', $consumption);

        return $consumption;
    }
}
