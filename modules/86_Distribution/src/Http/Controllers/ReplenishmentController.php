<?php

declare(strict_types=1);

namespace Modules\Distribution\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Distribution\Http\Controllers\Concerns\InteractsWithDistribution;
use Modules\Distribution\Http\Requests\AdjustProposalRequest;
use Modules\Distribution\Http\Requests\ApproveProposalsRequest;
use Modules\Distribution\Http\Requests\DistributionFilterRequest;
use Modules\Distribution\Http\Requests\GenerateProposalsRequest;
use Modules\Distribution\Http\Requests\SaveReplenishmentRuleRequest;
use Modules\Distribution\Http\Resources\ArrayResource;
use Modules\Distribution\Http\Resources\ReplenishmentProposalResource;
use Modules\Distribution\Http\Resources\ReplenishmentRuleResource;
use Modules\Distribution\Models\ReplenishmentProposal;
use Modules\Distribution\Models\ReplenishmentRule;
use Modules\Distribution\Services\ProposalService;
use Modules\Distribution\Services\ReplenishmentEngine;
use Modules\Distribution\Services\TransferPresenter;
use Modules\Inventory\Models\TransferOrder;
use OpenApi\Attributes as OA;

/**
 * Replenishment rules per warehouse and item, the proposals the engine generates (replenishment and lateral balancing) and their
 * conversion into transfer orders.
 */
final class ReplenishmentController extends Controller
{
    use ApiResponse;
    use InteractsWithDistribution;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    private const array RULE_DEFAULTS = [
        'safety_stock' => 0, 'reorder_point' => 0, 'max_stock' => 0, 'fixed_quantity' => 0, 'min_transfer_qty' => 0,
        'transfer_multiple' => 0, 'lead_time_days' => 1, 'priority' => 100,
    ];

    #[OA\Get(path: '/api/v1/distribution/replenishment-rules', operationId: 'distributionListReplenishmentRules', summary: 'List the replenishment rules', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Rules, paginated')])]
    public function rules(DistributionFilterRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', ReplenishmentRule::class);

        $page = ReplenishmentRule::query()
            ->with(['warehouse', 'sourceWarehouse', 'item'])
            ->when($request->input('warehouse_id'), static fn ($rows, $id) => $rows->where('warehouse_id', $id))
            ->when($request->input('stock_item_id'), static fn ($rows, $id) => $rows->where('stock_item_id', $id))
            ->orderBy('priority')
            ->orderBy('created_at')
            ->paginate($this->perPage(50));

        return $this->paged($page, ReplenishmentRuleResource::class);
    }

    #[OA\Get(path: '/api/v1/distribution/replenishment-rules/{id}', operationId: 'distributionShowReplenishmentRule', summary: 'Get a replenishment rule', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Rule')])]
    public function showRule(string $id): JsonResponse
    {
        $rule = ReplenishmentRule::query()->with(['warehouse', 'sourceWarehouse', 'item'])->findOrFail($id);
        Gate::authorize('view', $rule);

        return $this->successResponse(new ReplenishmentRuleResource($rule), $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/distribution/replenishment-rules', operationId: 'distributionCreateReplenishmentRule', summary: 'Create a replenishment rule', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 201, description: 'Rule created')])]
    public function storeRule(SaveReplenishmentRuleRequest $request): JsonResponse
    {
        Gate::authorize('create', ReplenishmentRule::class);

        $rule = ReplenishmentRule::create($this->payload($request->validated()));

        return $this->createdResponse(new ReplenishmentRuleResource($rule->load(['warehouse', 'sourceWarehouse', 'item'])), $this->message('saved'));
    }

    #[OA\Put(path: '/api/v1/distribution/replenishment-rules/{id}', operationId: 'distributionUpdateReplenishmentRule', summary: 'Update a replenishment rule', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Rule updated')])]
    public function updateRule(string $id, SaveReplenishmentRuleRequest $request): JsonResponse
    {
        $rule = ReplenishmentRule::query()->findOrFail($id);
        Gate::authorize('update', $rule);
        $rule->update($this->payload($request->validated()));

        return $this->successResponse(new ReplenishmentRuleResource($rule->refresh()->load(['warehouse', 'sourceWarehouse', 'item'])), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/distribution/replenishment-rules/{id}', operationId: 'distributionDeleteReplenishmentRule', summary: 'Delete a replenishment rule', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Rule deleted')])]
    public function destroyRule(string $id): JsonResponse
    {
        $rule = ReplenishmentRule::query()->findOrFail($id);
        Gate::authorize('delete', $rule);
        $rule->forceDelete();

        return $this->successResponse(null, $this->message('deleted'));
    }

    #[OA\Get(path: '/api/v1/distribution/proposals', operationId: 'distributionListProposals', summary: 'List the replenishment and balancing proposals', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Proposals, paginated')])]
    public function proposals(DistributionFilterRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', ReplenishmentProposal::class);

        $page = ReplenishmentProposal::query()
            ->with(['warehouse', 'sourceWarehouse', 'item'])
            ->when($request->input('status'), static fn ($rows, $status) => $rows->where('status', $status))
            ->when($request->input('proposal_type'), static fn ($rows, $type) => $rows->where('proposal_type', $type))
            ->when($request->input('warehouse_id'), static fn ($rows, $id) => $rows->where('warehouse_id', $id))
            ->orderByDesc('generated_at')
            ->orderBy('created_at')
            ->paginate($this->perPage(50));

        return $this->paged($page, ReplenishmentProposalResource::class);
    }

    #[OA\Get(path: '/api/v1/distribution/proposals/{id}', operationId: 'distributionShowProposal', summary: 'Get a proposal with its calculation', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Proposal')])]
    public function showProposal(string $id): JsonResponse
    {
        $proposal = ReplenishmentProposal::query()->with(['warehouse', 'sourceWarehouse', 'item'])->findOrFail($id);
        Gate::authorize('view', $proposal);

        return $this->successResponse(new ReplenishmentProposalResource($proposal), $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/distribution/proposals/generate', operationId: 'distributionGenerateProposals', summary: 'Run the replenishment and balancing engine', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Proposals generated')])]
    public function generate(GenerateProposalsRequest $request, ReplenishmentEngine $engine): JsonResponse
    {
        Gate::authorize('create', ReplenishmentProposal::class);

        $result = $engine->generate(array_values((array) $request->input('warehouse_ids', [])), array_values((array) $request->input('stock_item_ids', [])));

        return $this->successResponse(['created' => $result['created'], 'balancing' => $result['balancing'], 'shortfalls' => $result['shortfalls']], $this->message('proposals_generated'));
    }

    #[OA\Put(path: '/api/v1/distribution/proposals/{id}', operationId: 'distributionAdjustProposal', summary: 'Adjust the quantity of a pending proposal', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Proposal adjusted')])]
    public function adjust(string $id, AdjustProposalRequest $request, ProposalService $proposals): JsonResponse
    {
        $proposal = ReplenishmentProposal::query()->findOrFail($id);
        Gate::authorize('update', $proposal);
        $proposal = $proposals->adjust($proposal, $request->input('suggested_quantity'));

        return $this->successResponse(new ReplenishmentProposalResource($proposal->load(['warehouse', 'sourceWarehouse', 'item'])), $this->message('saved'));
    }

    #[OA\Post(path: '/api/v1/distribution/proposals/approve', operationId: 'distributionApproveProposals', summary: 'Turn proposals into transfer orders', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Transfers created, shortfalls flagged')])]
    public function approve(ApproveProposalsRequest $request, ProposalService $proposals, TransferPresenter $presenter): JsonResponse
    {
        Gate::authorize('create', ReplenishmentProposal::class);

        /** @var 'draft'|'approved' $status */
        $status = (string) $request->input('transfer_status');
        $result = $proposals->approve(array_values((array) $request->input('ids')), $status, $this->userId());
        $transfers = $presenter->orders(collect(TransferOrder::query()->whereIn('id', array_map(static fn (TransferOrder $order): string => (string) $order->id, $result['transfers']))->get()));

        return $this->successResponse([
            'transfers' => ArrayResource::collection($transfers),
            'shortfalls' => ReplenishmentProposalResource::collection(collect($result['shortfalls'])->each->load(['warehouse', 'sourceWarehouse', 'item'])),
        ], $this->message('proposals_approved'));
    }

    #[OA\Post(path: '/api/v1/distribution/proposals/{id}/reject', operationId: 'distributionRejectProposal', summary: 'Reject a proposal', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Proposal rejected')])]
    public function reject(string $id, ProposalService $proposals): JsonResponse
    {
        $proposal = ReplenishmentProposal::query()->findOrFail($id);
        Gate::authorize('update', $proposal);
        $proposal = $proposals->reject($proposal, $this->userId());

        return $this->successResponse(new ReplenishmentProposalResource($proposal->load(['warehouse', 'sourceWarehouse', 'item'])), $this->message('proposal_rejected'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(array $data): array
    {
        return array_merge(self::RULE_DEFAULTS, array_filter($data, static fn (mixed $value): bool => $value !== null) + array_intersect_key($data, array_flip(['source_warehouse_id', 'review_cycle_days', 'target_days_of_supply'])));
    }
}
