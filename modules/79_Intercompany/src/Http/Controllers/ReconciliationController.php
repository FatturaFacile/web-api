<?php

declare(strict_types=1);

namespace Modules\Intercompany\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\GeneralLedger\Models\ConsolidationGroup;
use Modules\Intercompany\Http\Requests\AdjustItemRequest;
use Modules\Intercompany\Http\Requests\AsOfDateRequest;
use Modules\Intercompany\Http\Requests\MatchItemRequest;
use Modules\Intercompany\Http\Requests\ReasonRequest;
use Modules\Intercompany\Http\Requests\RunReconciliationRequest;
use Modules\Intercompany\Http\Resources\ReconciliationBatchResource;
use Modules\Intercompany\Http\Resources\ReconciliationItemResource;
use Modules\Intercompany\Models\ReconciliationBatch;
use Modules\Intercompany\Models\ReconciliationItem;
use Modules\Intercompany\Services\ReciprocalReconciliationEngine;
use Modules\Intercompany\Support\GroupScope;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * The reciprocal reconciliation cockpit: batches, items with their anomalies, the quadrature matrix and the manual actions.
 */
final class ReconciliationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly GroupScope $scope,
        private readonly ReciprocalReconciliationEngine $engine,
    ) {}

    #[OA\Get(path: '/api/v1/intercompany/groups/{groupId}/reconciliation/batches', operationId: 'intercompanyListReconciliationBatches', summary: 'Reconciliation batches of a group', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'groupId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Batches')])]
    public function batches(Request $request, string $groupId): JsonResponse
    {
        Gate::authorize('intercompany.view');
        $group = $this->scope->group($groupId);
        $this->scope->assertReadable($request->user(), $group);

        return $this->successResponse(ReconciliationBatchResource::collection(ReconciliationBatch::query()->where('group_id', $group->id)->orderByDesc('as_of_date')->orderByDesc('created_at')->get()), 'Reconciliation batches');
    }

    #[OA\Post(path: '/api/v1/intercompany/groups/{groupId}/reconciliation/batches', operationId: 'intercompanyRunReconciliation', summary: 'Run the reciprocal reconciliation', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'groupId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'Batch')])]
    public function run(RunReconciliationRequest $request, string $groupId): JsonResponse
    {
        $group = $this->scope->group($groupId);
        $batch = $this->engine->run($request->user(), $group, CarbonImmutable::parse((string) $request->validated('as_of_date')), (bool) ($request->validated('auto_match') ?? true));

        return $this->successResponse(new ReconciliationBatchResource($batch), 'Reconciliation completed', Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/intercompany/reconciliation/batches/{id}', operationId: 'intercompanyShowReconciliationBatch', summary: 'A batch with its items', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Batch')])]
    public function show(Request $request, string $id): JsonResponse
    {
        Gate::authorize('intercompany.view');
        $batch = ReconciliationBatch::query()->findOrFail($id);
        $this->scope->assertReadable($request->user(), $this->scope->group((string) $batch->group_id));

        $items = ReconciliationItem::query()->where('batch_id', $batch->id)
            ->when($request->query('anomaly_type'), fn ($q, $value) => $q->where('anomaly_type', $value))
            ->when($request->query('status'), fn ($q, $value) => $q->where('status', $value))
            ->when($request->query('entity_a_tenant_id'), fn ($q, $value) => $q->where('entity_a_tenant_id', $value))
            ->when($request->query('entity_b_tenant_id'), fn ($q, $value) => $q->where('entity_b_tenant_id', $value))
            ->orderBy('entity_a_tenant_id')->orderBy('a_document_date')->orderBy('id')
            ->get();

        return $this->successResponse([...(new ReconciliationBatchResource($batch))->resolve($request), 'items' => ReconciliationItemResource::collection($items)->resolve($request)], 'Reconciliation batch');
    }

    #[OA\Get(path: '/api/v1/intercompany/groups/{groupId}/reconciliation/matrix', operationId: 'intercompanyReconciliationMatrix', summary: 'Quadrature matrix of the reciprocal balances', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'groupId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'as_of_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))], responses: [new OA\Response(response: 200, description: 'Matrix')])]
    public function matrix(AsOfDateRequest $request, string $groupId): JsonResponse
    {
        $group = $this->scope->group($groupId);
        $date = $request->validated('as_of_date') ? CarbonImmutable::parse((string) $request->validated('as_of_date')) : CarbonImmutable::today();

        return $this->successResponse($this->engine->matrix($request->user(), $group, $date), 'Quadrature matrix');
    }

    #[OA\Post(path: '/api/v1/intercompany/reconciliation/items/{id}/match', operationId: 'intercompanyMatchItem', summary: 'Match an item by hand', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Item')])]
    public function match(MatchItemRequest $request, string $id): JsonResponse
    {
        [$item, $group] = $this->load($request, $id);

        return $this->successResponse(new ReconciliationItemResource($this->engine->match($request->user(), $group, $item, (string) $request->validated('counterpart_document_id'))), 'Item matched');
    }

    #[OA\Post(path: '/api/v1/intercompany/reconciliation/items/{id}/unmatch', operationId: 'intercompanyUnmatchItem', summary: 'Undo the match of an item', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Item')])]
    public function unmatch(Request $request, string $id): JsonResponse
    {
        Gate::authorize('intercompany.manage');
        [$item, $group] = $this->load($request, $id);

        return $this->successResponse(new ReconciliationItemResource($this->engine->unmatch($request->user(), $group, $item)), 'Item unmatched');
    }

    #[OA\Post(path: '/api/v1/intercompany/reconciliation/items/{id}/dispute', operationId: 'intercompanyDisputeItem', summary: 'Dispute an item', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Item')])]
    public function dispute(ReasonRequest $request, string $id): JsonResponse
    {
        [$item, $group] = $this->load($request, $id);

        return $this->successResponse(new ReconciliationItemResource($this->engine->dispute($request->user(), $group, $item, (string) $request->validated('reason'))), 'Item disputed');
    }

    #[OA\Post(path: '/api/v1/intercompany/reconciliation/items/{id}/write-off', operationId: 'intercompanyWriteOffItem', summary: 'Write off an item', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Item')])]
    public function writeOff(ReasonRequest $request, string $id): JsonResponse
    {
        [$item, $group] = $this->load($request, $id);

        return $this->successResponse(new ReconciliationItemResource($this->engine->writeOff($request->user(), $group, $item, (string) $request->validated('reason'))), 'Item written off');
    }

    #[OA\Post(path: '/api/v1/intercompany/reconciliation/items/{id}/adjust', operationId: 'intercompanyAdjustItem', summary: 'Adjust an item', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Item')])]
    public function adjust(AdjustItemRequest $request, string $id): JsonResponse
    {
        [$item, $group] = $this->load($request, $id);

        return $this->successResponse(new ReconciliationItemResource($this->engine->adjust($request->user(), $group, $item, (string) $request->validated('reason'), (string) $request->validated('adjustment_amount'))), 'Item adjusted');
    }

    /**
     * @return array{0: ReconciliationItem, 1: ConsolidationGroup}
     */
    private function load(Request $request, string $id): array
    {
        $item = ReconciliationItem::query()->findOrFail($id);

        return [$item, $this->scope->group((string) $item->group_id)];
    }
}
