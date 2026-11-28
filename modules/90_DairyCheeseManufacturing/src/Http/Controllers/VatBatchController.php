<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\DairyCheeseManufacturing\Application\Commands\CompleteVatBatchCommand;
use Modules\DairyCheeseManufacturing\Application\Commands\CompleteVatBatchHandler;
use Modules\DairyCheeseManufacturing\Application\Commands\CreateVatBatchCommand;
use Modules\DairyCheeseManufacturing\Application\Commands\CreateVatBatchHandler;
use Modules\DairyCheeseManufacturing\Http\Requests\CompleteVatBatchRequest;
use Modules\DairyCheeseManufacturing\Http\Requests\CreateVatBatchRequest;
use Modules\DairyCheeseManufacturing\Http\Requests\ProcessVatBatchRequest;
use Modules\DairyCheeseManufacturing\Http\Requests\ViewRequest;
use Modules\DairyCheeseManufacturing\Http\Resources\VatBatchResource;
use Modules\DairyCheeseManufacturing\Models\CheeseVatBatch;
use Modules\DairyCheeseManufacturing\Services\VatBatchService;
use OpenApi\Attributes as OA;

/**
 * Vat batch (cotta) production with milk traceability and wheel generation.
 */
final class VatBatchController extends Controller
{
    use ApiResponse;
    use DairyControllerSupport;

    public function __construct(private readonly VatBatchService $batches) {}

    #[OA\Get(path: '/api/v1/dairy-cheese/vat-batches', operationId: 'dairyCheeseVatBatchesList', summary: 'List the vat batches', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], responses: [new OA\Response(response: 200, description: 'Vat batches')])]
    public function index(ViewRequest $request): JsonResponse
    {
        $f = $request->validated();
        $query = CheeseVatBatch::query()->with(['tankUsages.tank', 'draws.breeder'])->orderByDesc('production_date')->orderByDesc('created_at');

        foreach (['status', 'cheese_type'] as $field) {
            if (! empty($f[$field])) {
                $query->where($field, $f[$field]);
            }
        }

        $page = $query->paginate($this->perPage($request));

        return $this->successResponse(VatBatchResource::collection($page->items()), 'OK', 200, $this->pageMeta($page));
    }

    #[OA\Post(path: '/api/v1/dairy-cheese/vat-batches', operationId: 'dairyCheeseVatBatchesCreate', summary: 'Create a vat batch drawing milk from the tanks (FIFO)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], responses: [new OA\Response(response: 201, description: 'Vat batch created')])]
    public function store(CreateVatBatchRequest $request, CreateVatBatchHandler $handler): JsonResponse
    {
        $batch = $handler->handle(new CreateVatBatchCommand($request->validated(), $this->userId($request)));

        return $this->successResponse(new VatBatchResource($batch), $this->message('batch_created'), 201);
    }

    #[OA\Get(path: '/api/v1/dairy-cheese/vat-batches/{id}', operationId: 'dairyCheeseVatBatchesShow', summary: 'Show a vat batch with its composition', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Vat batch')])]
    public function show(string $id): JsonResponse
    {
        $this->view();

        return $this->successResponse(new VatBatchResource(CheeseVatBatch::query()->findOrFail($id)));
    }

    #[OA\Put(path: '/api/v1/dairy-cheese/vat-batches/{id}/process', operationId: 'dairyCheeseVatBatchesProcess', summary: 'Log starter, rennet, pH / temperature curve and curd yield', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Vat batch updated')])]
    public function process(ProcessVatBatchRequest $request, string $id): JsonResponse
    {
        $batch = $this->batches->process(CheeseVatBatch::query()->findOrFail($id), $request->validated());

        return $this->successResponse(new VatBatchResource($batch), $this->message('saved'));
    }

    #[OA\Post(path: '/api/v1/dairy-cheese/vat-batches/{id}/complete', operationId: 'dairyCheeseVatBatchesComplete', summary: 'Complete the batch: 24 h yield, plaque range and aging wheels', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Vat batch completed')])]
    public function complete(CompleteVatBatchRequest $request, CompleteVatBatchHandler $handler, string $id): JsonResponse
    {
        $batch = $handler->handle(new CompleteVatBatchCommand($id, $request->validated()));

        return $this->successResponse(new VatBatchResource($batch), $this->message('batch_completed'));
    }
}
