<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\DairyCheeseManufacturing\Application\Commands\RegisterMilkDeliveriesBulkCommand;
use Modules\DairyCheeseManufacturing\Application\Commands\RegisterMilkDeliveriesBulkHandler;
use Modules\DairyCheeseManufacturing\Application\Commands\RegisterMilkDeliveryCommand;
use Modules\DairyCheeseManufacturing\Application\Commands\RegisterMilkDeliveryHandler;
use Modules\DairyCheeseManufacturing\Http\Requests\BulkDeliveriesRequest;
use Modules\DairyCheeseManufacturing\Http\Requests\ManageRequest;
use Modules\DairyCheeseManufacturing\Http\Requests\RegisterDeliveryRequest;
use Modules\DairyCheeseManufacturing\Http\Requests\ViewRequest;
use Modules\DairyCheeseManufacturing\Http\Resources\DeliveryResource;
use Modules\DairyCheeseManufacturing\Models\MilkDelivery;
use Modules\DairyCheeseManufacturing\Services\DeliveryService;
use OpenApi\Attributes as OA;

/**
 * Daily raw milk intake ledger.
 */
final class DeliveryController extends Controller
{
    use ApiResponse;
    use DairyControllerSupport;

    public function __construct(private readonly DeliveryService $deliveries) {}

    #[OA\Get(path: '/api/v1/dairy-cheese/deliveries', operationId: 'dairyCheeseDeliveriesList', summary: 'Milk intake ledger (paginated)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], responses: [new OA\Response(response: 200, description: 'Deliveries')])]
    public function index(ViewRequest $request): JsonResponse
    {
        $f = $request->validated();
        $query = MilkDelivery::query()->with(['breeder', 'tank'])->orderByDesc('collection_date')->orderByRaw("CASE session WHEN 'evening' THEN 0 ELSE 1 END")->orderByDesc('created_at');

        foreach (['breeder_id', 'tank_id', 'status'] as $field) {
            if (! empty($f[$field])) {
                $query->where($field, $f[$field]);
            }
        }

        if (! empty($f['date_from'])) {
            $query->whereDate('collection_date', '>=', $f['date_from']);
        }

        if (! empty($f['date_to'])) {
            $query->whereDate('collection_date', '<=', $f['date_to']);
        }

        $page = $query->paginate($this->perPage($request));

        return $this->successResponse(DeliveryResource::collection($page->items()), 'OK', 200, $this->pageMeta($page));
    }

    #[OA\Post(path: '/api/v1/dairy-cheese/deliveries', operationId: 'dairyCheeseDeliveriesCreate', summary: 'Register a milk delivery into a tank', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], responses: [new OA\Response(response: 201, description: 'Delivery registered')])]
    public function store(RegisterDeliveryRequest $request, RegisterMilkDeliveryHandler $handler): JsonResponse
    {
        $delivery = $handler->handle(new RegisterMilkDeliveryCommand($request->validated(), $this->userId($request)));

        return $this->successResponse(new DeliveryResource($delivery), $this->message('delivery_registered'), 201);
    }

    #[OA\Post(path: '/api/v1/dairy-cheese/deliveries/bulk', operationId: 'dairyCheeseDeliveriesBulk', summary: 'Register many deliveries at once (quick-entry grid)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], responses: [new OA\Response(response: 201, description: 'Created and rejected rows')])]
    public function bulk(BulkDeliveriesRequest $request, RegisterMilkDeliveriesBulkHandler $handler): JsonResponse
    {
        $result = $handler->handle(new RegisterMilkDeliveriesBulkCommand($request->validated('rows'), $this->userId($request)));

        return $this->successResponse(['created' => DeliveryResource::collection($result['created'])->resolve(), 'rejected' => $result['rejected']], $this->message('deliveries_registered'), 201);
    }

    #[OA\Delete(path: '/api/v1/dairy-cheese/deliveries/{id}', operationId: 'dairyCheeseDeliveriesDelete', summary: 'Delete a delivery that is not settled or used', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Delivery deleted')])]
    public function destroy(ManageRequest $request, string $id): JsonResponse
    {
        $this->deliveries->delete(MilkDelivery::query()->findOrFail($id));

        return $this->successResponse(null, $this->message('deleted'));
    }

    #[OA\Post(path: '/api/v1/dairy-cheese/deliveries/{id}/release', operationId: 'dairyCheeseDeliveriesRelease', summary: 'Release milk held in quarantine', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Delivery released')])]
    public function release(ManageRequest $request, string $id): JsonResponse
    {
        $delivery = $this->deliveries->release(MilkDelivery::query()->findOrFail($id));

        return $this->successResponse(new DeliveryResource($delivery->load(['breeder', 'tank'])), $this->message('saved'));
    }

    #[OA\Post(path: '/api/v1/dairy-cheese/deliveries/{id}/reject', operationId: 'dairyCheeseDeliveriesReject', summary: 'Reject (discard) a delivery', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Delivery rejected')])]
    public function reject(ManageRequest $request, string $id): JsonResponse
    {
        $delivery = $this->deliveries->reject(MilkDelivery::query()->findOrFail($id));

        return $this->successResponse(new DeliveryResource($delivery->load(['breeder', 'tank'])), $this->message('saved'));
    }
}
