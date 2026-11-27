<?php

declare(strict_types=1);

namespace Modules\Distribution\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Distribution\Http\Controllers\Concerns\InteractsWithDistribution;
use Modules\Distribution\Http\Requests\DistributionFilterRequest;
use Modules\Distribution\Http\Requests\ReconcileThreePlRequest;
use Modules\Distribution\Http\Requests\ReturnConsignmentRequest;
use Modules\Distribution\Http\Requests\SettleConsignmentRequest;
use Modules\Distribution\Http\Resources\ArrayResource;
use Modules\Distribution\Http\Resources\ConsignmentSettlementResource;
use Modules\Distribution\Http\Resources\ThreePlReconciliationResource;
use Modules\Distribution\Models\ConsignmentSettlement;
use Modules\Distribution\Models\ThreePlReconciliation;
use Modules\Distribution\Services\ConsignmentService;
use Modules\Distribution\Services\ThreePlReconciliationService;
use Modules\Distribution\Services\TransferPresenter;
use Modules\Inventory\Models\Warehouse;
use OpenApi\Attributes as OA;

/**
 * Consignment stock (conto deposito): stock held at third parties and for third parties, consumption settlements, returns, and
 * the stock reconciliation of third-party logistics warehouses.
 */
final class ConsignmentController extends Controller
{
    use ApiResponse;
    use InteractsWithDistribution;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/distribution/consignment/stock', operationId: 'distributionConsignmentStock', summary: 'Stock held on consignment with days held and value', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Consignment stock rows with the total value')])]
    public function stock(DistributionFilterRequest $request, ConsignmentService $consignment): JsonResponse
    {
        Gate::authorize('viewAny', ConsignmentSettlement::class);

        $result = $consignment->stock($request->input('direction'));

        return $this->successResponse(ArrayResource::collection($result['rows']), $this->message('listed'), 200, ['total' => count($result['rows']), 'total_value' => $result['total_value']]);
    }

    #[OA\Get(path: '/api/v1/distribution/consignment/settlements', operationId: 'distributionListSettlements', summary: 'List the consignment settlements', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Settlements, paginated')])]
    public function settlements(DistributionFilterRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', ConsignmentSettlement::class);

        $page = ConsignmentSettlement::query()->with(['warehouse', 'lines.item'])
            ->when($request->input('warehouse_id'), static fn ($rows, $id) => $rows->where('warehouse_id', $id))
            ->when($request->input('status'), static fn ($rows, $status) => $rows->where('status', $status))
            ->orderByDesc('settled_at')
            ->orderByDesc('created_at')
            ->paginate($this->perPage(25));

        return $this->paged($page, ConsignmentSettlementResource::class);
    }

    #[OA\Post(path: '/api/v1/distribution/consignment/settlements', operationId: 'distributionCreateSettlement', summary: 'Record the consumption reported at a consignment warehouse', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 201, description: 'Settlement recorded and stock issued')])]
    public function settle(SettleConsignmentRequest $request, ConsignmentService $consignment): JsonResponse
    {
        Gate::authorize('create', ConsignmentSettlement::class);

        $settlement = $consignment->settle(
            Warehouse::query()->findOrFail((string) $request->input('warehouse_id')),
            array_values((array) $request->input('lines')),
            $request->input('reference'),
            $request->input('settled_at'),
            $request->input('notes'),
            $this->userId(),
        );

        return $this->createdResponse(new ConsignmentSettlementResource($settlement), $this->message('settlement_recorded'));
    }

    #[OA\Post(path: '/api/v1/distribution/consignment/returns', operationId: 'distributionConsignmentReturn', summary: 'Return unsold goods from a consignment warehouse', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 201, description: 'Return transfer created')])]
    public function returnStock(ReturnConsignmentRequest $request, ConsignmentService $consignment, TransferPresenter $presenter): JsonResponse
    {
        Gate::authorize('create', ConsignmentSettlement::class);

        $order = $consignment->returnStock(
            Warehouse::query()->findOrFail((string) $request->input('warehouse_id')),
            Warehouse::query()->findOrFail((string) $request->input('destination_warehouse_id')),
            array_values((array) $request->input('lines')),
            $request->input('notes'),
            $this->userId(),
        );

        return $this->createdResponse(new ArrayResource($presenter->orders(collect([$order]))[0]), $this->message('consignment_return_created'));
    }

    #[OA\Get(path: '/api/v1/distribution/threepl/reconciliations', operationId: 'distributionListThreePlReconciliations', summary: 'List the 3PL stock reconciliations', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Reconciliations, paginated')])]
    public function reconciliations(DistributionFilterRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', ThreePlReconciliation::class);

        $page = ThreePlReconciliation::query()->with('warehouse')
            ->when($request->input('warehouse_id'), static fn ($rows, $id) => $rows->where('warehouse_id', $id))
            ->orderByDesc('reconciled_at')
            ->paginate($this->perPage(25));

        return $this->paged($page, ThreePlReconciliationResource::class);
    }

    #[OA\Post(path: '/api/v1/distribution/threepl/reconciliations', operationId: 'distributionCreateThreePlReconciliation', summary: 'Compare the stock reported by a 3PL provider with the system stock', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 201, description: 'Reconciliation recorded')])]
    public function reconcile(ReconcileThreePlRequest $request, ThreePlReconciliationService $reconciliation): JsonResponse
    {
        Gate::authorize('create', ThreePlReconciliation::class);

        $result = $reconciliation->reconcile(
            Warehouse::query()->findOrFail((string) $request->input('warehouse_id')),
            array_values((array) $request->input('lines')),
            $request->input('external_reference'),
        );

        return $this->createdResponse(new ThreePlReconciliationResource($result), $this->message('reconciliation_recorded'));
    }
}
