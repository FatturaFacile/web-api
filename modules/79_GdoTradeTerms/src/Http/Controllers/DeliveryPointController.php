<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\GdoTradeTerms\Application\Commands\AssignInvoiceDeliveryPointsCommand;
use Modules\GdoTradeTerms\Application\Commands\AssignInvoiceDeliveryPointsHandler;
use Modules\GdoTradeTerms\Application\Commands\SaveDeliveryPointCommand;
use Modules\GdoTradeTerms\Application\Commands\SaveDeliveryPointHandler;
use Modules\GdoTradeTerms\Application\Queries\GetDeliveryPointsHandler;
use Modules\GdoTradeTerms\Application\Queries\GetDeliveryPointsQuery;
use Modules\GdoTradeTerms\Http\Controllers\Concerns\InteractsWithGdo;
use Modules\GdoTradeTerms\Http\Requests\AssignInvoicesRequest;
use Modules\GdoTradeTerms\Http\Requests\ListFiltersRequest;
use Modules\GdoTradeTerms\Http\Requests\SaveDeliveryPointRequest;
use Modules\GdoTradeTerms\Http\Resources\DeliveryPointResource;
use Modules\GdoTradeTerms\Models\GdoDeliveryPoint;
use Modules\GdoTradeTerms\Services\DeliveryPointService;
use OpenApi\Attributes as OA;

/**
 * Stores, Ce.Di. and head offices of the retailers, and the assignment of invoices to them.
 */
final class DeliveryPointController extends Controller
{
    use ApiResponse;
    use InteractsWithGdo;

    #[OA\Get(path: '/api/v1/gdo-trade-terms/delivery-points', operationId: 'gdoTradeTermsListDeliveryPoints', summary: 'List stores and Ce.Di. of the retailers', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], responses: [new OA\Response(response: 200, description: 'Delivery points, paginated')])]
    public function index(ListFiltersRequest $request, GetDeliveryPointsHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', GdoDeliveryPoint::class);

        return $this->paged($handler->handle(new GetDeliveryPointsQuery($request->input('contact_id'), $request->input('q'), $this->perPage(100))), DeliveryPointResource::class);
    }

    #[OA\Post(path: '/api/v1/gdo-trade-terms/delivery-points', operationId: 'gdoTradeTermsCreateDeliveryPoint', summary: 'Create a delivery point', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], responses: [new OA\Response(response: 201, description: 'Delivery point created')])]
    public function store(SaveDeliveryPointRequest $request, SaveDeliveryPointHandler $handler): JsonResponse
    {
        Gate::authorize('create', GdoDeliveryPoint::class);

        return $this->successResponse(new DeliveryPointResource($handler->handle(new SaveDeliveryPointCommand($request->validated(), $this->tenantId(), $this->userId()))), $this->message('saved'), 201);
    }

    #[OA\Put(path: '/api/v1/gdo-trade-terms/delivery-points/{id}', operationId: 'gdoTradeTermsUpdateDeliveryPoint', summary: 'Update a delivery point', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Delivery point updated')])]
    public function update(SaveDeliveryPointRequest $request, string $id, SaveDeliveryPointHandler $handler): JsonResponse
    {
        Gate::authorize('update', GdoDeliveryPoint::query()->findOrFail($id));

        return $this->successResponse(new DeliveryPointResource($handler->handle(new SaveDeliveryPointCommand($request->validated(), $this->tenantId(), $this->userId(), $id))), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/gdo-trade-terms/delivery-points/{id}', operationId: 'gdoTradeTermsDeleteDeliveryPoint', summary: 'Delete a delivery point', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Delivery point deleted')])]
    public function destroy(string $id, DeliveryPointService $points): JsonResponse
    {
        $point = GdoDeliveryPoint::query()->findOrFail($id);
        Gate::authorize('delete', $point);
        $points->delete($point, $this->userId());

        return $this->successResponse(null, $this->message('deleted'));
    }

    #[OA\Post(path: '/api/v1/gdo-trade-terms/invoice-delivery-points', operationId: 'gdoTradeTermsAssignInvoices', summary: 'Assign invoices to the delivery points they were delivered to', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], responses: [new OA\Response(response: 200, description: 'Invoices assigned')])]
    public function assignInvoices(AssignInvoicesRequest $request, AssignInvoiceDeliveryPointsHandler $handler): JsonResponse
    {
        Gate::authorize('create', GdoDeliveryPoint::class);

        $count = $handler->handle(new AssignInvoiceDeliveryPointsCommand($request->validated('assignments'), $this->tenantId(), $this->userId()));

        return $this->successResponse(['assigned' => $count], $this->message('saved'));
    }
}
