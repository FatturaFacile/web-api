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
use Modules\Procurement\Http\Resources\PurchaseRequisitionResource;
use Modules\Procurement\Models\PurchaseRequisition;

final class PurchaseRequisitionController extends Controller
{
    #[EnterpriseEndpoint(summary: 'List purchase requisitions', tags: ['Procurement'])]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PurchaseRequisition::class);

        $query = PurchaseRequisition::with(['requestor', 'lines'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $requisitions = $query->paginate((int) $request->query('per_page', 25));

        return response()->json(PurchaseRequisitionResource::collection($requisitions)->response()->getData(true));
    }

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

    #[EnterpriseEndpoint(summary: 'Show purchase requisition details', tags: ['Procurement'])]
    public function show(string $id): JsonResponse
    {
        $requisition = PurchaseRequisition::with(['requestor', 'lines'])->findOrFail($id);
        $this->authorize('view', $requisition);

        return response()->json(new PurchaseRequisitionResource($requisition));
    }

    #[EnterpriseEndpoint(summary: 'Submit requisition for approval via Workflow Engine V2', tags: ['Procurement'])]
    public function submitApproval(string $id, SubmitRequisitionForApprovalAction $action): JsonResponse
    {
        $requisition = PurchaseRequisition::findOrFail($id);
        $this->authorize('submitApproval', $requisition);

        $submitted = $action->execute($requisition);

        return response()->json(new PurchaseRequisitionResource($submitted));
    }
}
