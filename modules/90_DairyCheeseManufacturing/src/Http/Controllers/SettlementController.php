<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\DairyCheeseManufacturing\Application\Commands\GenerateMonthlySettlementCommand;
use Modules\DairyCheeseManufacturing\Application\Commands\GenerateMonthlySettlementHandler;
use Modules\DairyCheeseManufacturing\Http\Requests\GenerateSettlementRequest;
use Modules\DairyCheeseManufacturing\Http\Requests\SettlementDocumentRequest;
use Modules\DairyCheeseManufacturing\Http\Requests\SettleRequest;
use Modules\DairyCheeseManufacturing\Http\Requests\ViewRequest;
use Modules\DairyCheeseManufacturing\Http\Resources\SettlementResource;
use Modules\DairyCheeseManufacturing\Models\MonthlySettlement;
use Modules\DairyCheeseManufacturing\Services\SettlementDocumentService;
use Modules\DairyCheeseManufacturing\Services\SettlementService;
use OpenApi\Attributes as OA;

/**
 * Monthly milk settlements and their billing documents.
 */
final class SettlementController extends Controller
{
    use ApiResponse;
    use DairyControllerSupport;

    public function __construct(private readonly SettlementService $settlements, private readonly SettlementDocumentService $documents) {}

    #[OA\Get(path: '/api/v1/dairy-cheese/settlements', operationId: 'dairyCheeseSettlementsList', summary: 'List the monthly settlements', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], responses: [new OA\Response(response: 200, description: 'Settlements')])]
    public function index(ViewRequest $request): JsonResponse
    {
        $f = $request->validated();
        $query = MonthlySettlement::query()->with('breeder')->orderByDesc('period')->orderBy('created_at');

        foreach (['period', 'breeder_id', 'status'] as $field) {
            if (! empty($f[$field])) {
                $query->where($field, $f[$field]);
            }
        }

        return $this->successResponse(SettlementResource::collection($query->get()));
    }

    #[OA\Post(path: '/api/v1/dairy-cheese/settlements/generate', operationId: 'dairyCheeseSettlementsGenerate', summary: 'Generate the monthly settlements of the breeders', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], responses: [new OA\Response(response: 201, description: 'Settlements generated')])]
    public function generate(GenerateSettlementRequest $request, GenerateMonthlySettlementHandler $handler): JsonResponse
    {
        $settlements = $handler->handle(new GenerateMonthlySettlementCommand(
            (string) $request->validated('period'), (string) $request->validated('grid_id'), $request->validated('breeder_ids'), $this->userId($request),
        ));

        return $this->successResponse(['settlements' => SettlementResource::collection($settlements)->resolve()], $this->message('settlements_generated'), 201);
    }

    #[OA\Get(path: '/api/v1/dairy-cheese/settlements/{id}', operationId: 'dairyCheeseSettlementsShow', summary: 'Show a settlement with its itemized payout', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Settlement')])]
    public function show(string $id): JsonResponse
    {
        $this->view();

        return $this->successResponse(new SettlementResource(MonthlySettlement::query()->with('breeder')->findOrFail($id)));
    }

    #[OA\Post(path: '/api/v1/dairy-cheese/settlements/{id}/approve', operationId: 'dairyCheeseSettlementsApprove', summary: 'Approve a draft settlement', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Settlement approved')])]
    public function approve(SettleRequest $request, string $id): JsonResponse
    {
        $settlement = $this->settlements->approve(MonthlySettlement::query()->with('breeder')->findOrFail($id));

        return $this->successResponse(new SettlementResource($settlement), $this->message('settlement_approved'));
    }

    #[OA\Post(path: '/api/v1/dairy-cheese/settlements/{id}/document', operationId: 'dairyCheeseSettlementsDocument', summary: 'Generate the self-billing document, credit note or invoice of an approved settlement', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Settlement with its document')])]
    public function document(SettlementDocumentRequest $request, string $id): JsonResponse
    {
        $settlement = $this->documents->create(MonthlySettlement::query()->with('breeder')->findOrFail($id), (string) $request->validated('document_type'));

        return $this->successResponse(new SettlementResource($settlement), $this->message('document_created'));
    }
}
