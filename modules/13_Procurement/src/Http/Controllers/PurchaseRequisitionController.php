<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Procurement\Actions\CreatePurchaseRequisitionAction;
use Modules\Procurement\Actions\SubmitRequisitionForApprovalAction;
use Modules\Procurement\DTOs\CreatePurchaseRequisitionDTO;
use Modules\Procurement\Http\Requests\StorePurchaseRequisitionRequest;
use Modules\Procurement\Http\Requests\UpdatePurchaseRequisitionRequest;
use Modules\Procurement\Http\Requests\WorkflowActionRequest;
use Modules\Procurement\Http\Resources\PurchaseRequisitionResource;
use Modules\Procurement\Models\PurchaseRequisition;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Procurement Requisitions', description: 'Internal procurement requisitions and authorization workflows')]
final class PurchaseRequisitionController extends Controller
{
    #[OA\Get(
        path: '/api/v1/procurement/requisitions',
        summary: 'List purchase requisitions',
        description: 'Retrieve a paginated list of purchase requisitions with filtering and sorting.',
        tags: ['Procurement Requisitions'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', description: 'Page number', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Items per page', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'status', in: 'query', description: 'Filter by status (draft, pending_approval, approved, rejected, cancelled)', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'urgency', in: 'query', description: 'Filter by urgency level (low, medium, high, emergency)', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', description: 'Search title or requisition number', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', description: 'Sort field (-created_at, requisition_number)', required: false, schema: new OA\Schema(type: 'string', default: '-created_at')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated requisitions list', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProcurementPurchaseRequisition')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/ProcurementApiPaginationMeta'),
                ]
            )),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementApiErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List purchase requisitions', tags: ['Procurement'])]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PurchaseRequisition::class);

        $query = PurchaseRequisition::with(['requestor', 'lines']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('urgency')) {
            $query->where('urgency', $request->input('urgency'));
        }

        if ($request->filled('search')) {
            $search = '%'.$request->string('search')->value().'%';
            $query->where(function ($q) use ($search): void {
                $q->where('title', 'like', $search)
                    ->orWhere('requisition_number', 'like', $search);
            });
        }

        $sort = $request->query('sort', '-created_at');
        $direction = str_starts_with((string) $sort, '-') ? 'desc' : 'asc';
        $column = ltrim((string) $sort, '-');

        if (in_array($column, ['created_at', 'requisition_number', 'total_estimated_amount', 'required_by_date'], true)) {
            $query->orderBy($column, $direction);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $requisitions = $query->paginate((int) $request->query('per_page', 25));

        return response()->json(PurchaseRequisitionResource::collection($requisitions)->response()->getData(true));
    }

    #[OA\Post(
        path: '/api/v1/procurement/requisitions',
        summary: 'Create a new purchase requisition',
        description: 'Creates a draft purchase requisition with multiple line items.',
        tags: ['Procurement Requisitions'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title', 'required_by_date', 'urgency', 'lines'],
                properties: [
                    new OA\Property(property: 'title', type: 'string', example: 'IT Equipment Refresh Q4'),
                    new OA\Property(property: 'required_by_date', type: 'string', format: 'date', example: '2026-12-01'),
                    new OA\Property(property: 'urgency', type: 'string', example: 'medium', enum: ['low', 'medium', 'high', 'emergency']),
                    new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
                    new OA\Property(property: 'notes', type: 'string', example: 'Standard department requisition'),
                    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'description', type: 'string', example: 'Dell UltraSharp 27 Monitor'),
                            new OA\Property(property: 'quantity_requested', type: 'number', example: 5),
                            new OA\Property(property: 'uom', type: 'string', example: 'PCS'),
                            new OA\Property(property: 'estimated_unit_price', type: 'number', example: 450.00),
                        ]
                    )),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Requisition created', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementPurchaseRequisition')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementApiValidationErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Create a new purchase requisition', tags: ['Procurement'])]
    public function store(StorePurchaseRequisitionRequest $request, CreatePurchaseRequisitionAction $action): JsonResponse
    {
        $this->authorize('create', PurchaseRequisition::class);

        $dto = new CreatePurchaseRequisitionDTO(
            title: $request->string('title')->value(),
            requestorUserId: (string) auth()->id(),
            requiredByDate: $request->string('required_by_date')->value(),
            lines: (array) $request->input('lines'),
            urgency: $request->string('urgency', 'medium')->value(),
            projectId: $request->input('project_id'),
            costCenterId: $request->input('cost_center_id'),
            departmentId: $request->input('department_id'),
            branchId: $request->input('branch_id'),
            currency: $request->string('currency', 'EUR')->value(),
            notes: $request->input('notes')
        );

        $requisition = $action->execute($dto);

        return response()->json(new PurchaseRequisitionResource($requisition), 201);
    }

    #[OA\Get(
        path: '/api/v1/procurement/requisitions/{id}',
        summary: 'Show purchase requisition details',
        description: 'Retrieve requisition with line items, requestor, and approval workflow status.',
        tags: ['Procurement Requisitions'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'Requisition UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Requisition details', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementPurchaseRequisition')),
            new OA\Response(response: 404, description: 'Requisition not found', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementApiErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Show purchase requisition details', tags: ['Procurement'])]
    public function show(string $id): JsonResponse
    {
        $requisition = PurchaseRequisition::with(['requestor', 'lines'])->findOrFail($id);
        $this->authorize('view', $requisition);

        return response()->json(new PurchaseRequisitionResource($requisition));
    }

    #[OA\Put(
        path: '/api/v1/procurement/requisitions/{id}',
        summary: 'Update purchase requisition',
        description: 'Updates a draft requisition and its line items.',
        tags: ['Procurement Requisitions'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'Requisition UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Requisition updated', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementPurchaseRequisition')),
            new OA\Response(response: 403, description: 'Requisition is locked or not in draft', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementApiErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Update purchase requisition', tags: ['Procurement'])]
    public function update(UpdatePurchaseRequisitionRequest $request, string $id): JsonResponse
    {
        $requisition = PurchaseRequisition::findOrFail($id);
        $this->authorize('update', $requisition);

        $validated = $request->validated();
        $requisition->update(collect($validated)->except('lines')->toArray());

        return response()->json(new PurchaseRequisitionResource($requisition->fresh(['requestor', 'lines'])));
    }

    #[OA\Delete(
        path: '/api/v1/procurement/requisitions/{id}',
        summary: 'Delete draft purchase requisition',
        description: 'Soft-deletes a purchase requisition if still in draft state.',
        tags: ['Procurement Requisitions'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'Requisition UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Requisition deleted successfully'),
            new OA\Response(response: 403, description: 'Requisition is locked or submitted'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Delete draft purchase requisition', tags: ['Procurement'])]
    public function destroy(string $id): JsonResponse
    {
        $requisition = PurchaseRequisition::findOrFail($id);
        $this->authorize('delete', $requisition);

        $requisition->delete();

        return response()->json(['message' => 'Purchase requisition deleted successfully']);
    }

    #[OA\Post(
        path: '/api/v1/procurement/requisitions/{id}/submit-approval',
        summary: 'Submit requisition for approval via Workflow Engine V2',
        description: 'Transitions requisition from draft to pending_approval and triggers Workflow Engine V2.',
        tags: ['Procurement Requisitions'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'Requisition UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Requisition submitted for approval', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementPurchaseRequisition')),
            new OA\Response(response: 403, description: 'Requisition is locked or not in draft'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Submit requisition for approval via Workflow Engine V2', tags: ['Procurement'])]
    public function submitApproval(string $id, SubmitRequisitionForApprovalAction $action): JsonResponse
    {
        $requisition = PurchaseRequisition::findOrFail($id);
        $this->authorize('submitApproval', $requisition);

        $submitted = $action->execute($requisition);

        return response()->json(new PurchaseRequisitionResource($submitted));
    }

    #[OA\Post(
        path: '/api/v1/procurement/requisitions/{id}/approve',
        summary: 'Approve purchase requisition',
        description: 'Approves requisition subject to segregation of duties (author cannot approve own request).',
        tags: ['Procurement Requisitions'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'Requisition UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Requisition approved', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementPurchaseRequisition')),
            new OA\Response(response: 403, description: 'Segregation of duties violation or invalid state'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Approve purchase requisition', tags: ['Procurement'])]
    public function approve(WorkflowActionRequest $request, string $id): JsonResponse
    {
        $requisition = PurchaseRequisition::findOrFail($id);
        $this->authorize('approve', $requisition);

        $requisition->update([
            'status' => 'approved',
            'is_locked' => false,
            'approved_at' => now(),
            'approved_by' => auth()->id(),
        ]);

        return response()->json(new PurchaseRequisitionResource($requisition->fresh(['requestor', 'lines'])));
    }

    #[OA\Post(
        path: '/api/v1/procurement/requisitions/{id}/reject',
        summary: 'Reject purchase requisition',
        description: 'Rejects a requisition submitted for approval, unlocking the requisition and recording the reason.',
        tags: ['Procurement Requisitions'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'Requisition UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Requisition rejected', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementPurchaseRequisition')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Reject purchase requisition', tags: ['Procurement'])]
    public function reject(WorkflowActionRequest $request, string $id): JsonResponse
    {
        $requisition = PurchaseRequisition::findOrFail($id);
        $this->authorize('reject', $requisition);

        $requisition->update([
            'status' => 'rejected',
            'is_locked' => false,
            'rejection_reason' => $request->string('reason')->value(),
        ]);

        return response()->json(new PurchaseRequisitionResource($requisition->fresh(['requestor', 'lines'])));
    }

    #[OA\Post(
        path: '/api/v1/procurement/requisitions/{id}/cancel',
        summary: 'Cancel purchase requisition',
        description: 'Cancels an active or pending requisition.',
        tags: ['Procurement Requisitions'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'Requisition UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Requisition cancelled', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementPurchaseRequisition')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Cancel purchase requisition', tags: ['Procurement'])]
    public function cancel(string $id): JsonResponse
    {
        $requisition = PurchaseRequisition::findOrFail($id);
        $this->authorize('cancel', $requisition);

        $requisition->update([
            'status' => 'cancelled',
            'is_locked' => false,
        ]);

        return response()->json(new PurchaseRequisitionResource($requisition->fresh(['requestor', 'lines'])));
    }
}
