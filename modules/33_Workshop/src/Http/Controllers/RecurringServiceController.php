<?php

declare(strict_types=1);

namespace Modules\Workshop\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Workshop\Http\Requests\StoreRecurringServiceRequest;
use Modules\Workshop\Http\Resources\WorkshopResource;
use Modules\Workshop\Models\RecurringService;
use Modules\Workshop\Services\WorkshopService;
use OpenApi\Attributes as OA;

/**
 * Services billed automatically at every period, such as the yearly tyre storage.
 */
final class RecurringServiceController extends Controller
{
    public function __construct(private readonly WorkshopService $workshop) {}

    /**
     * Return the recurring services with their subscription.
     */
    #[OA\Get(path: '/api/v1/workshop/recurring-services', operationId: 'listWorkshopRecurringServices', description: 'Return the recurring services with their subscription.', summary: 'List recurring services', security: [['bearerAuth' => []]], tags: ['Workshop'], parameters: [
        new OA\Parameter(name: 'vehicle_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [
        new OA\Response(response: 200, description: 'Services', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordCollection')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', RecurringService::class);

        return WorkshopResource::collection(RecurringService::query()
            ->with('subscription')
            ->when($request->filled('vehicle_id'), fn ($query) => $query->where('vehicle_id', $request->string('vehicle_id')->toString()))
            ->latest()
            ->paginate(max(1, min($request->integer('per_page', 25), 100))));
    }

    /**
     * Start a service billed at every period for the owner of the vehicle; the subscriptions:process command issues
     * the invoices.
     */
    #[OA\Post(path: '/api/v1/workshop/recurring-services', operationId: 'storeWorkshopRecurringService', description: 'Start a service billed at every period for the owner of the vehicle; the subscriptions:process command issues the invoices.', summary: 'Start recurring service', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecurringServiceRequest')), tags: ['Workshop'], responses: [
        new OA\Response(response: 201, description: 'Service started', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function store(StoreRecurringServiceRequest $request): JsonResponse
    {
        $this->authorize('create', RecurringService::class);

        return (new WorkshopResource($this->workshop->startRecurringService($request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Cancel the subscription of a recurring service: no further period is billed.
     */
    #[OA\Post(path: '/api/v1/workshop/recurring-services/{id}/stop', operationId: 'stopWorkshopRecurringService', description: 'Cancel the subscription of a recurring service: no further period is billed.', summary: 'Stop recurring service', security: [['bearerAuth' => []]], tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 200, description: 'Service stopped', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
    ])]
    public function stop(string $id): WorkshopResource
    {
        $service = RecurringService::query()->findOrFail($id);
        $this->authorize('update', $service);

        return new WorkshopResource($this->workshop->stopRecurringService($service));
    }
}
