<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Procurement;

use App\Application\Procurement\PurchaseRequisition\Commands\ConvertRequisitionsToProcurementCommand;
use App\Application\Procurement\PurchaseRequisition\Commands\CreatePurchaseRequisitionCommand;
use App\Application\Procurement\PurchaseRequisition\Commands\DeletePurchaseRequisitionCommand;
use App\Application\Procurement\PurchaseRequisition\Commands\ProcessRequisitionApprovalCommand;
use App\Application\Procurement\PurchaseRequisition\Commands\SubmitPurchaseRequisitionCommand;
use App\Application\Procurement\PurchaseRequisition\Commands\UpdatePurchaseRequisitionCommand;
use App\Application\Procurement\PurchaseRequisition\Handlers\CheckPurchaseRequisitionBudgetHandler;
use App\Application\Procurement\PurchaseRequisition\Handlers\ConvertRequisitionsToProcurementHandler;
use App\Application\Procurement\PurchaseRequisition\Handlers\CreatePurchaseRequisitionHandler;
use App\Application\Procurement\PurchaseRequisition\Handlers\DeletePurchaseRequisitionHandler;
use App\Application\Procurement\PurchaseRequisition\Handlers\GetPendingApprovalsHandler;
use App\Application\Procurement\PurchaseRequisition\Handlers\GetPurchaseRequisitionDetailHandler;
use App\Application\Procurement\PurchaseRequisition\Handlers\GetPurchaseRequisitionsHandler;
use App\Application\Procurement\PurchaseRequisition\Handlers\ProcessRequisitionApprovalHandler;
use App\Application\Procurement\PurchaseRequisition\Handlers\SubmitPurchaseRequisitionHandler;
use App\Application\Procurement\PurchaseRequisition\Handlers\UpdatePurchaseRequisitionHandler;
use App\Application\Procurement\PurchaseRequisition\Queries\CheckPurchaseRequisitionBudgetQuery;
use App\Application\Procurement\PurchaseRequisition\Queries\GetPendingApprovalsQuery;
use App\Application\Procurement\PurchaseRequisition\Queries\GetPurchaseRequisitionDetailQuery;
use App\Application\Procurement\PurchaseRequisition\Queries\GetPurchaseRequisitionsQuery;
use App\Domain\Procurement\PurchaseRequisition\Enums\ApprovalChannel;
use App\Domain\Procurement\PurchaseRequisition\Enums\RequisitionStatus;
use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisition;
use App\Http\Controllers\Controller;
use App\Http\Requests\Procurement\PurchaseRequisition\ApprovePurchaseRequisitionRequest;
use App\Http\Requests\Procurement\PurchaseRequisition\BudgetPreviewRequest;
use App\Http\Requests\Procurement\PurchaseRequisition\ConvertPurchaseRequisitionsRequest;
use App\Http\Requests\Procurement\PurchaseRequisition\OneClickApprovalActionRequest;
use App\Http\Requests\Procurement\PurchaseRequisition\RejectPurchaseRequisitionRequest;
use App\Http\Requests\Procurement\PurchaseRequisition\StorePurchaseRequisitionRequest;
use App\Http\Requests\Procurement\PurchaseRequisition\SubmitPurchaseRequisitionRequest;
use App\Http\Requests\Procurement\PurchaseRequisition\UpdatePurchaseRequisitionRequest;
use App\Http\Resources\Procurement\PurchaseRequisition\BudgetPreviewResource;
use App\Http\Resources\Procurement\PurchaseRequisition\ConversionResultResource;
use App\Http\Resources\Procurement\PurchaseRequisition\PurchaseRequisitionResource;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use OpenApi\Attributes as OA;

/**
 * REST Controller for Mod 044 — Purchase Requisitions (RDA / PR) & Internal Approval Workflow.
 */
final class PurchaseRequisitionController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly GetPurchaseRequisitionsHandler $listHandler,
        private readonly GetPurchaseRequisitionDetailHandler $detailHandler,
        private readonly CreatePurchaseRequisitionHandler $createHandler,
        private readonly UpdatePurchaseRequisitionHandler $updateHandler,
        private readonly DeletePurchaseRequisitionHandler $deleteHandler,
        private readonly CheckPurchaseRequisitionBudgetHandler $budgetPreviewHandler,
        private readonly SubmitPurchaseRequisitionHandler $submitHandler,
        private readonly ProcessRequisitionApprovalHandler $approvalHandler,
        private readonly GetPendingApprovalsHandler $pendingApprovalsHandler,
        private readonly ConvertRequisitionsToProcurementHandler $convertHandler,
    ) {}

    #[OA\Get(
        path: '/api/v1/procurement/purchase-requisitions',
        summary: 'List Purchase Requisitions (paginated and filtered)',
        tags: ['Procurement - Purchase Requisitions (Mod 044)'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Paginated list of purchase requisitions')]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', PurchaseRequisition::class);

        $paginator = $this->listHandler->handle(new GetPurchaseRequisitionsQuery(
            tenantId: $this->requireTenantId(),
            status: $request->string('status')->toString() ?: null,
            costCenterId: $request->string('cost_center_id')->toString() ?: null,
            projectId: $request->string('project_id')->toString() ?: null,
            urgency: $request->string('urgency')->toString() ?: null,
            requesterId: $request->string('requester_id')->toString() ?: null,
            dateFrom: $request->string('date_from')->toString() ?: null,
            dateTo: $request->string('date_to')->toString() ?: null,
            search: $request->string('search')->toString() ?: null,
            page: max(1, (int) $request->integer('page', 1)),
            perPage: max(1, min(100, (int) $request->integer('per_page', 20))),
        ));

        return PurchaseRequisitionResource::collection($paginator);
    }

    #[OA\Post(
        path: '/api/v1/procurement/purchase-requisitions',
        summary: 'Create a draft Purchase Requisition',
        tags: ['Procurement - Purchase Requisitions (Mod 044)'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 201, description: 'Purchase requisition created')]
    )]
    public function store(StorePurchaseRequisitionRequest $request): JsonResponse
    {
        Gate::authorize('create', PurchaseRequisition::class);

        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        $requisition = $this->createHandler->handle(new CreatePurchaseRequisitionCommand(
            tenantId: $this->requireTenantId(),
            requesterUserId: (string) $user->id,
            departmentId: $validated['department_id'] ?? null,
            costCenterId: $validated['cost_center_id'] ?? null,
            projectId: $validated['project_id'] ?? null,
            urgency: (string) ($validated['urgency'] ?? 'normal'),
            requestedDeliveryDate: $validated['requested_delivery_date'] ?? null,
            economicJustification: $validated['economic_justification'] ?? null,
            currency: (string) ($validated['currency'] ?? 'EUR'),
            title: $validated['title'] ?? null,
            notes: $validated['notes'] ?? null,
            lines: array_values($validated['lines']),
        ));

        return (new PurchaseRequisitionResource($requisition))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/procurement/purchase-requisitions/{id}',
        summary: 'Show Purchase Requisition details including lines, approval steps, audit logs, and document links',
        tags: ['Procurement - Purchase Requisitions (Mod 044)'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Purchase requisition details')]
    )]
    public function show(string $id): PurchaseRequisitionResource
    {
        $detail = $this->detailHandler->handle(new GetPurchaseRequisitionDetailQuery(
            tenantId: $this->requireTenantId(),
            requisitionId: $id,
        ));

        Gate::authorize('view', $detail['requisition']);

        return (new PurchaseRequisitionResource($detail['requisition']))->withApprovalSteps($detail['approval_steps']);
    }

    #[OA\Put(
        path: '/api/v1/procurement/purchase-requisitions/{id}',
        summary: 'Update an editable (draft or budget_exceeded_blocked) Purchase Requisition',
        tags: ['Procurement - Purchase Requisitions (Mod 044)'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Purchase requisition updated')]
    )]
    public function update(UpdatePurchaseRequisitionRequest $request, string $id): PurchaseRequisitionResource
    {
        $tenantId = $this->requireTenantId();
        $existing = $this->findRequisitionOrFail($tenantId, $id);
        Gate::authorize('update', $existing);

        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        $requisition = $this->updateHandler->handle(new UpdatePurchaseRequisitionCommand(
            tenantId: $tenantId,
            requisitionId: $id,
            actorUserId: (string) $user->id,
            departmentId: $validated['department_id'] ?? null,
            hasDepartmentId: array_key_exists('department_id', $validated),
            costCenterId: $validated['cost_center_id'] ?? null,
            hasCostCenterId: array_key_exists('cost_center_id', $validated),
            projectId: $validated['project_id'] ?? null,
            hasProjectId: array_key_exists('project_id', $validated),
            urgency: $validated['urgency'] ?? null,
            requestedDeliveryDate: $validated['requested_delivery_date'] ?? null,
            hasRequestedDeliveryDate: array_key_exists('requested_delivery_date', $validated),
            economicJustification: $validated['economic_justification'] ?? null,
            hasEconomicJustification: array_key_exists('economic_justification', $validated),
            currency: $validated['currency'] ?? null,
            title: $validated['title'] ?? null,
            hasTitle: array_key_exists('title', $validated),
            notes: $validated['notes'] ?? null,
            hasNotes: array_key_exists('notes', $validated),
            lines: array_key_exists('lines', $validated) ? array_values($validated['lines']) : null,
        ));

        return new PurchaseRequisitionResource($requisition);
    }

    #[OA\Delete(
        path: '/api/v1/procurement/purchase-requisitions/{id}',
        summary: 'Delete a draft Purchase Requisition',
        tags: ['Procurement - Purchase Requisitions (Mod 044)'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Draft purchase requisition deleted')]
    )]
    public function destroy(Request $request, string $id): JsonResponse
    {
        $tenantId = $this->requireTenantId();
        $existing = $this->findRequisitionOrFail($tenantId, $id);
        Gate::authorize('delete', $existing);

        /** @var User $user */
        $user = $request->user();

        $this->deleteHandler->handle(new DeletePurchaseRequisitionCommand(
            tenantId: $tenantId,
            requisitionId: $id,
            actorUserId: (string) $user->id,
        ));

        return response()->json([
            'message' => __('purchase_requisition.messages.deleted'),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/procurement/purchase-requisitions/budget-preview',
        summary: 'Preview real-time cost center budget availability without mutating state',
        tags: ['Procurement - Purchase Requisitions (Mod 044)'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Real-time budget availability result')]
    )]
    public function budgetPreview(BudgetPreviewRequest $request): BudgetPreviewResource
    {
        Gate::authorize('viewAny', PurchaseRequisition::class);

        $validated = $request->validated();

        $result = $this->budgetPreviewHandler->handle(new CheckPurchaseRequisitionBudgetQuery(
            tenantId: $this->requireTenantId(),
            costCenterId: $validated['cost_center_id'] ?? null,
            currency: (string) ($validated['currency'] ?? 'EUR'),
            fiscalYear: (int) ($validated['fiscal_year'] ?? now()->year),
            excludeRequisitionId: $validated['exclude_requisition_id'] ?? null,
            lines: array_values($validated['lines']),
        ));

        return new BudgetPreviewResource($result);
    }

    #[OA\Post(
        path: '/api/v1/procurement/purchase-requisitions/{id}/submit',
        summary: 'Submit a Purchase Requisition for real-time budget check and multi-level approval routing',
        tags: ['Procurement - Purchase Requisitions (Mod 044)'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Submission processed')]
    )]
    public function submit(SubmitPurchaseRequisitionRequest $request, string $id): PurchaseRequisitionResource
    {
        $tenantId = $this->requireTenantId();
        $existing = $this->findRequisitionOrFail($tenantId, $id);
        Gate::authorize('submit', $existing);

        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();
        $channel = ApprovalChannel::tryFrom((string) ($validated['channel'] ?? 'web')) ?? ApprovalChannel::Web;

        $requisition = $this->submitHandler->handle(new SubmitPurchaseRequisitionCommand(
            tenantId: $tenantId,
            requisitionId: $id,
            actorUserId: (string) $user->id,
            channel: $channel,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            acknowledgeBudgetWarning: (bool) ($validated['acknowledge_budget_warning'] ?? false),
        ));

        $blocked = $requisition->statusEnum() === RequisitionStatus::BudgetExceededBlocked;

        return (new PurchaseRequisitionResource($requisition))->additional([
            'budget_blocked' => $blocked,
            'message' => $blocked
                ? __('purchase_requisition.messages.budget_blocked')
                : __('purchase_requisition.messages.submitted_for_approval'),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/procurement/purchase-requisitions/{id}/approve',
        summary: 'Approve the current approval step of a Purchase Requisition in-app',
        tags: ['Procurement - Purchase Requisitions (Mod 044)'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Approval step recorded')]
    )]
    public function approve(ApprovePurchaseRequisitionRequest $request, string $id): PurchaseRequisitionResource
    {
        $tenantId = $this->requireTenantId();
        $existing = $this->findRequisitionOrFail($tenantId, $id);
        Gate::authorize('decide', $existing);

        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();
        $channel = ApprovalChannel::tryFrom((string) ($validated['channel'] ?? 'web')) ?? ApprovalChannel::Web;

        $requisition = $this->approvalHandler->handle(new ProcessRequisitionApprovalCommand(
            tenantId: $tenantId,
            requisitionId: $id,
            actorUserId: (string) $user->id,
            action: 'approve',
            comment: $validated['comment'] ?? null,
            channel: $channel,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        ));

        return (new PurchaseRequisitionResource($requisition))->additional([
            'message' => $this->decisionMessage($requisition),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/procurement/purchase-requisitions/{id}/reject',
        summary: 'Reject a pending Purchase Requisition in-app with mandatory reason comment',
        tags: ['Procurement - Purchase Requisitions (Mod 044)'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Rejection recorded')]
    )]
    public function reject(RejectPurchaseRequisitionRequest $request, string $id): PurchaseRequisitionResource
    {
        $tenantId = $this->requireTenantId();
        $existing = $this->findRequisitionOrFail($tenantId, $id);
        Gate::authorize('decide', $existing);

        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();
        $channel = ApprovalChannel::tryFrom((string) ($validated['channel'] ?? 'web')) ?? ApprovalChannel::Web;

        $requisition = $this->approvalHandler->handle(new ProcessRequisitionApprovalCommand(
            tenantId: $tenantId,
            requisitionId: $id,
            actorUserId: (string) $user->id,
            action: 'reject',
            comment: (string) $validated['comment'],
            channel: $channel,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        ));

        return (new PurchaseRequisitionResource($requisition))->additional([
            'message' => __('purchase_requisition.messages.requisition_rejected'),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/procurement/purchase-requisitions/one-click-action',
        summary: 'Execute a signed single-use 1-click Email/Push approval or rejection token action',
        tags: ['Procurement - Purchase Requisitions (Mod 044)'],
        responses: [new OA\Response(response: 200, description: 'One-click decision applied')]
    )]
    public function oneClickAction(OneClickApprovalActionRequest $request): PurchaseRequisitionResource
    {
        $validated = $request->validated();

        $requisition = $this->approvalHandler->handle(new ProcessRequisitionApprovalCommand(
            tenantId: null,
            requisitionId: null,
            actorUserId: null,
            action: (string) $validated['action'],
            comment: $validated['comment'] ?? null,
            channel: ApprovalChannel::EmailOneClick,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            oneClickToken: (string) $validated['token'],
        ));

        return (new PurchaseRequisitionResource($requisition))->additional([
            'message' => $this->decisionMessage($requisition),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/procurement/purchase-requisitions/pending-approvals',
        summary: 'List Purchase Requisitions currently awaiting action from the authenticated approver',
        tags: ['Procurement - Purchase Requisitions (Mod 044)'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Paginated pending approval queue')]
    )]
    public function pendingApprovals(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', PurchaseRequisition::class);

        /** @var User $user */
        $user = $request->user();

        $paginator = $this->pendingApprovalsHandler->handle(new GetPendingApprovalsQuery(
            tenantId: $this->requireTenantId(),
            user: $user,
            page: max(1, (int) $request->integer('page', 1)),
            perPage: max(1, min(100, (int) $request->integer('per_page', 20))),
        ));

        return PurchaseRequisitionResource::collection($paginator);
    }

    #[OA\Post(
        path: '/api/v1/procurement/purchase-requisitions/convert',
        summary: 'Convert approved or partially converted Purchase Requisition lines into a draft Mod 045 RFQ or Mod 046 PO',
        tags: ['Procurement - Purchase Requisitions (Mod 044)'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 201, description: 'Downstream RFQ/PO created with bidirectional traceability')]
    )]
    public function convert(ConvertPurchaseRequisitionsRequest $request): JsonResponse
    {
        Gate::authorize('convert', PurchaseRequisition::class);

        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        $result = $this->convertHandler->handle(new ConvertRequisitionsToProcurementCommand(
            tenantId: $this->requireTenantId(),
            actorUserId: (string) $user->id,
            targetType: (string) $validated['target_type'],
            supplierId: $validated['supplier_id'] ?? null,
            supplierIds: isset($validated['supplier_ids']) ? array_values($validated['supplier_ids']) : null,
            title: $validated['title'] ?? null,
            submissionDeadline: $validated['submission_deadline'] ?? null,
            expectedDeliveryDate: $validated['expected_delivery_date'] ?? null,
            notes: $validated['notes'] ?? null,
            items: array_values($validated['items']),
        ));

        return (new ConversionResultResource($result))
            ->additional([
                'message' => __('purchase_requisition.messages.converted'),
            ])
            ->response()
            ->setStatusCode(201);
    }

    private function requireTenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }

    private function findRequisitionOrFail(string $tenantId, string $id): PurchaseRequisition
    {
        return PurchaseRequisition::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($id)
            ->firstOrFail();
    }

    private function decisionMessage(PurchaseRequisition $requisition): string
    {
        return match ($requisition->statusEnum()) {
            RequisitionStatus::Approved => __('purchase_requisition.messages.requisition_approved'),
            RequisitionStatus::Rejected => __('purchase_requisition.messages.requisition_rejected'),
            default => __('purchase_requisition.messages.step_approved_advanced'),
        };
    }
}
