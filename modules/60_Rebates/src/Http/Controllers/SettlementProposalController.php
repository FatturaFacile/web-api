<?php

declare(strict_types=1);

namespace Modules\Rebates\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Rebates\Http\Controllers\Concerns\InteractsWithRebates;
use Modules\Rebates\Http\Requests\ProposalRequest;
use Modules\Rebates\Http\Requests\SettlementTransitionRequest;
use Modules\Rebates\Http\Resources\SettlementResource;
use Modules\Rebates\Models\RebateAgreement;
use Modules\Rebates\Models\RebateSettlement;
use Modules\Rebates\Services\SettlementProposalService;
use OpenApi\Attributes as OA;

/**
 * Settlement proposals of supplier rebates: draft, submit, approve, execute (credit note request, charge invoice or
 * open-item set-off against a supplier payable) or cancel.
 */
final class SettlementProposalController extends Controller
{
    use InteractsWithRebates;

    public function __construct(private readonly SettlementProposalService $proposals) {}

    #[OA\Get(path: '/api/v1/rebates/settlement-proposals', operationId: 'listRebateSettlementProposals', description: 'Settlement proposals of supplier rebates with their analytical lines. Filters: agreement_id, status.', summary: 'List settlement proposals', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Rebates'], responses: [
        new OA\Response(response: 200, description: 'Settlement proposals', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', RebateSettlement::class);
        $filters = $request->validate([
            'agreement_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:'.implode(',', RebateSettlement::Statuses)],
        ]);

        return SettlementResource::collection(
            RebateSettlement::query()
                ->with(['agreement', 'lines'])
                ->where('tenant_id', $this->tenantId())
                ->whereNotNull('execution_mode')
                ->when(isset($filters['agreement_id']), static fn ($query) => $query->where('agreement_id', $filters['agreement_id']))
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->orderByDesc('created_at')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Get(path: '/api/v1/rebates/settlement-proposals/{id}', operationId: 'showRebateSettlementProposal', description: 'A settlement proposal with its analytical lines and accruals.', summary: 'Show a settlement proposal', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Rebates'], responses: [
        new OA\Response(response: 200, description: 'Settlement proposal', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(response: 404, description: 'Proposal not found'),
    ])]
    public function show(string $id): SettlementResource
    {
        $settlement = RebateSettlement::query()->with(['agreement', 'lines', 'accruals'])->findOrFail($id);
        $this->authorize('view', $settlement);

        return new SettlementResource($settlement);
    }

    #[OA\Post(path: '/api/v1/rebates/agreements/{id}/settlement-proposals', operationId: 'createRebateSettlementProposal', description: 'Proposes the settlement of the open accruals: analytical breakdown, gross, net and VAT amounts and the execution mode (credit_note_request, charge_invoice, ap_set_off).', summary: 'Create a settlement proposal', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Rebates'], responses: [
        new OA\Response(response: 201, description: 'Draft settlement proposal', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(response: 422, description: 'Nothing to settle or invalid payload'),
    ])]
    public function store(ProposalRequest $request, string $id): JsonResponse
    {
        $agreement = RebateAgreement::query()->findOrFail($id);
        $this->authorize('update', $agreement);

        return (new SettlementResource($this->proposals->propose($agreement, $request->validated(), $this->userId())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Post(path: '/api/v1/rebates/settlement-proposals/{id}/submit', operationId: 'submitRebateSettlementProposal', description: 'Sends a draft proposal to approval.', summary: 'Submit a settlement proposal', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Rebates'], responses: [
        new OA\Response(response: 200, description: 'Proposal pending approval', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(response: 422, description: 'Invalid transition or version conflict'),
    ])]
    public function submit(SettlementTransitionRequest $request, string $id): SettlementResource
    {
        $settlement = RebateSettlement::query()->findOrFail($id);
        $this->authorize('update', $settlement);

        return new SettlementResource($this->proposals->submit($settlement, $this->version($request)));
    }

    #[OA\Post(path: '/api/v1/rebates/settlement-proposals/{id}/approve', operationId: 'approveRebateSettlementProposal', description: 'Approves a proposal that is pending approval.', summary: 'Approve a settlement proposal', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Rebates'], responses: [
        new OA\Response(response: 200, description: 'Approved proposal', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(response: 422, description: 'Invalid transition or version conflict'),
    ])]
    public function approve(SettlementTransitionRequest $request, string $id): SettlementResource
    {
        $settlement = RebateSettlement::query()->findOrFail($id);
        $this->authorize('update', $settlement);

        return new SettlementResource($this->proposals->approve($settlement, $this->version($request), $this->userId()));
    }

    #[OA\Post(path: '/api/v1/rebates/settlement-proposals/{id}/execute', operationId: 'executeRebateSettlementProposal', description: 'Executes an approved proposal: requests the credit note, issues the charge invoice draft or sets the amount off against the chosen supplier payable (posting to the ledger when payables_account_id is sent).', summary: 'Execute a settlement proposal', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Rebates'], responses: [
        new OA\Response(response: 200, description: 'Settled proposal', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(response: 422, description: 'Invalid transition, version conflict or insufficient open balance'),
    ])]
    public function execute(SettlementTransitionRequest $request, string $id): SettlementResource
    {
        $settlement = RebateSettlement::query()->findOrFail($id);
        $this->authorize('update', $settlement);

        return new SettlementResource($this->proposals->execute($settlement, $request->validated(), $this->version($request), $this->userId()));
    }

    #[OA\Post(path: '/api/v1/rebates/settlement-proposals/{id}/cancel', operationId: 'cancelRebateSettlementProposal', description: 'Cancels a proposal that is not settled; its accruals become open again.', summary: 'Cancel a settlement proposal', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Rebates'], responses: [
        new OA\Response(response: 200, description: 'Cancelled proposal', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(response: 422, description: 'Invalid transition or version conflict'),
    ])]
    public function cancel(SettlementTransitionRequest $request, string $id): SettlementResource
    {
        $settlement = RebateSettlement::query()->findOrFail($id);
        $this->authorize('update', $settlement);

        return new SettlementResource($this->proposals->cancel($settlement, $this->version($request)));
    }

    private function version(SettlementTransitionRequest $request): ?int
    {
        $version = $request->validated('version');

        return $version === null ? null : (int) $version;
    }
}
