<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Returns\Http\Controllers\Concerns\InteractsWithReturns;
use Modules\Returns\Http\Requests\RefurbishedWorkOrderRequest;
use Modules\Returns\Http\Requests\ReturnGradingStoreRequest;
use Modules\Returns\Http\Resources\RefurbishedItemResource;
use Modules\Returns\Http\Resources\ReturnGradingInspectionResource;
use Modules\Returns\Models\RefurbishedItem;
use Modules\Returns\Models\ReturnGradingInspection;
use Modules\Returns\Services\ReturnGradingService;
use OpenApi\Attributes as OA;

/**
 * Return Inspection, Grading (A/B/C/Scrap) & Refurbished B-Stock endpoints (Mod 232).
 */
final class ReturnGradingController extends Controller
{
    use InteractsWithReturns;

    public function __construct(
        private readonly ReturnGradingService $grading,
    ) {}

    #[OA\Get(path: '/api/v1/returns/grading-inspections', operationId: 'listReturnGradingInspections', summary: 'List return grading inspections', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], responses: [
        new OA\Response(response: 200, description: 'Grading inspections'),
    ])]
    public function indexInspections(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ReturnGradingInspection::class);
        $grade = $request->query('grade');

        return ReturnGradingInspectionResource::collection(
            ReturnGradingInspection::query()
                ->with(['product', 'customerReturn', 'refurbishedItem'])
                ->where('tenant_id', $this->tenantId())
                ->when(is_string($grade) && $grade !== '', static fn ($q) => $q->where('grade', $grade))
                ->orderByDesc('inspected_at')
                ->paginate($this->perPage())
        );
    }

    #[OA\Post(path: '/api/v1/returns/grading-inspections', operationId: 'storeReturnGradingInspection', summary: 'Record a return inspection and grading with mandatory photo evidence', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], responses: [
        new OA\Response(response: 201, description: 'Grading recorded'),
    ])]
    public function storeInspection(ReturnGradingStoreRequest $request): JsonResponse
    {
        $this->authorize('create', ReturnGradingInspection::class);
        $record = $this->grading->inspectAndGrade($this->tenantId(), $request->validated(), $this->userId());

        return (new ReturnGradingInspectionResource($record))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/returns/refurbished-items', operationId: 'listRefurbishedItems', summary: 'List refurbished / B-Stock SKU inventory items', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], responses: [
        new OA\Response(response: 200, description: 'Refurbished items'),
    ])]
    public function indexRefurbished(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', RefurbishedItem::class);
        $grade = $request->query('grade');

        return RefurbishedItemResource::collection(
            RefurbishedItem::query()
                ->with(['originalProduct', 'refurbishedProduct'])
                ->where('tenant_id', $this->tenantId())
                ->when(is_string($grade) && $grade !== '', static fn ($q) => $q->where('grade', $grade))
                ->orderByDesc('created_at')
                ->paginate($this->perPage())
        );
    }

    #[OA\Post(path: '/api/v1/returns/refurbished-items/{id}/complete-work-order', operationId: 'completeRefurbishedWorkOrder', summary: 'Complete reconditioning work order for a B-Stock / Refurbished item', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], responses: [
        new OA\Response(response: 200, description: 'Work order updated'),
    ])]
    public function completeWorkOrder(RefurbishedWorkOrderRequest $request, string $id): RefurbishedItemResource
    {
        $item = RefurbishedItem::query()->findOrFail($id);
        $this->authorize('update', $item);

        return new RefurbishedItemResource($this->grading->completeWorkOrder($item, $request->validated()));
    }
}
