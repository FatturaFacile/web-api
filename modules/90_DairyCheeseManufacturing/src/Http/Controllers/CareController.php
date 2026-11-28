<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\DairyCheeseManufacturing\Application\Commands\ExecuteCareBatchCommand;
use Modules\DairyCheeseManufacturing\Application\Commands\ExecuteCareBatchHandler;
use Modules\DairyCheeseManufacturing\Http\Requests\ExecuteCareBatchRequest;
use Modules\DairyCheeseManufacturing\Http\Requests\ExecuteCareRequest;
use Modules\DairyCheeseManufacturing\Http\Requests\ScheduleCareRequest;
use Modules\DairyCheeseManufacturing\Http\Requests\ViewRequest;
use Modules\DairyCheeseManufacturing\Http\Resources\CareLogResource;
use Modules\DairyCheeseManufacturing\Models\AgingCareLog;
use Modules\DairyCheeseManufacturing\Services\CareService;
use OpenApi\Attributes as OA;

/**
 * Care scheduler of the aging cellar.
 */
final class CareController extends Controller
{
    use ApiResponse;
    use DairyControllerSupport;

    public function __construct(private readonly CareService $care) {}

    #[OA\Get(path: '/api/v1/dairy-cheese/care-logs', operationId: 'dairyCheeseCareLogsList', summary: 'Scheduled and executed care operations', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], responses: [new OA\Response(response: 200, description: 'Care logs')])]
    public function index(ViewRequest $request): JsonResponse
    {
        $page = $this->care->query($request->validated())->paginate($this->perPage($request));

        return $this->successResponse(CareLogResource::collection($page->items()), 'OK', 200, $this->pageMeta($page));
    }

    #[OA\Post(path: '/api/v1/dairy-cheese/care-logs', operationId: 'dairyCheeseCareLogsSchedule', summary: 'Schedule a care operation', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], responses: [new OA\Response(response: 201, description: 'Operation scheduled')])]
    public function store(ScheduleCareRequest $request): JsonResponse
    {
        return $this->successResponse(new CareLogResource($this->care->schedule($request->validated(), $this->userId($request))), $this->message('saved'), 201);
    }

    #[OA\Post(path: '/api/v1/dairy-cheese/care-logs/{id}/execute', operationId: 'dairyCheeseCareLogsExecute', summary: 'Mark a scheduled operation as executed', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Operation executed')])]
    public function execute(ExecuteCareRequest $request, string $id): JsonResponse
    {
        $log = $this->care->execute(AgingCareLog::query()->findOrFail($id), $request->validated('performed_by'), $request->validated('notes'));

        return $this->successResponse(new CareLogResource($log), $this->message('care_executed'));
    }

    #[OA\Post(path: '/api/v1/dairy-cheese/care-logs/execute-batch', operationId: 'dairyCheeseCareLogsExecuteBatch', summary: 'Mark a whole shelf, rack or batch as turned, brined, brushed or washed', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], responses: [new OA\Response(response: 200, description: 'Operation executed')])]
    public function executeBatch(ExecuteCareBatchRequest $request, ExecuteCareBatchHandler $handler): JsonResponse
    {
        $v = $request->validated();
        $log = $handler->handle(new ExecuteCareBatchCommand((string) $v['care_type'], (string) $v['scope'], (string) $v['target_id'], $v['performed_by'] ?? null, $v['notes'] ?? null));

        return $this->successResponse(new CareLogResource($log), $this->message('care_executed'));
    }
}
