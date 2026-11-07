<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PassiveInvoice;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Procurement\Http\Requests\StoreInvoiceApprovalMatrixRequest;
use Modules\Procurement\Http\Requests\SubmitInvoiceApprovalRequest;
use Modules\Procurement\Http\Resources\InvoiceApprovalMatrixResource;
use Modules\Procurement\Http\Resources\InvoiceApprovalResource;
use Modules\Procurement\Services\PassiveInvoiceApprovalService;
use Modules\Workflow\Models\ApprovalMatrix;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Starts and inspects supplier invoice approvals. The decisions themselves go through the workflow API, which enforces
 * the four-eyes rule: POST /api/v1/workflows/steps/{stepId}/decide.
 */
final class InvoiceApprovalController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PassiveInvoiceApprovalService $approvals,
        private readonly TenantContext $tenantContext,
    ) {}

    #[OA\Get(
        path: '/api/v1/procurement/supplier-invoices/{id}/approval',
        operationId: 'getSupplierInvoiceApproval',
        description: 'Approval state of a supplier invoice with its steps.',
        summary: 'Show invoice approval',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Supplier Invoice Approval'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Approval', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/InvoiceApprovalResource')])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new InvoiceApprovalResource(PassiveInvoice::query()->findOrFail($id)));
    }

    #[OA\Post(
        path: '/api/v1/procurement/supplier-invoices/{id}/submit-approval',
        operationId: 'submitSupplierInvoiceApproval',
        description: 'Start the approval chosen by amount and cost centre. The caller becomes the maker and cannot approve. Below every threshold the invoice clears at once.',
        summary: 'Submit invoice for approval',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/SubmitInvoiceApprovalRequest')),
        tags: ['Supplier Invoice Approval'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Submitted', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/InvoiceApprovalResource')])),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Already running or decided', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function submit(SubmitInvoiceApprovalRequest $request, string $id): JsonResponse
    {
        $invoice = $this->approvals->submit(PassiveInvoice::query()->findOrFail($id), $request->validated('cost_center_id'), $request->user());

        return $this->successResponse(new InvoiceApprovalResource($invoice), 'Approval submitted');
    }

    #[OA\Get(
        path: '/api/v1/invoice-approvals/matrices',
        operationId: 'listInvoiceApprovalMatrices',
        description: 'Approval rules of supplier invoices. Finance roles only.',
        summary: 'List approval matrices',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Supplier Invoice Approval'],
        responses: [
            new OA\Response(response: 200, description: 'Matrices', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InvoiceApprovalMatrixResource'))])),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function matrices(): JsonResponse
    {
        return $this->successResponse(InvoiceApprovalMatrixResource::collection(
            ApprovalMatrix::query()->where('target_entity_type', PassiveInvoice::class)->where('is_active', true)->with(['rules', 'tiers'])->orderBy('name')->get()
        ));
    }

    #[OA\Post(
        path: '/api/v1/invoice-approvals/matrices',
        operationId: 'createInvoiceApprovalMatrix',
        description: 'Create an approval rule. Levels run one after the other and each applies inside its amount thresholds. A rule with a cost centre wins over a general one. New invoices wait for approval from now on.',
        summary: 'Create approval matrix',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/StoreInvoiceApprovalMatrixRequest')),
        tags: ['Supplier Invoice Approval'],
        responses: [
            new OA\Response(response: 201, description: 'Matrix created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/InvoiceApprovalMatrixResource')])),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function storeMatrix(StoreInvoiceApprovalMatrixRequest $request): JsonResponse
    {
        $matrix = $this->approvals->createMatrix((string) $this->tenantContext->getTenantId(), $request->validated());

        return $this->successResponse(new InvoiceApprovalMatrixResource($matrix), 'Matrix created', Response::HTTP_CREATED);
    }

    #[OA\Delete(
        path: '/api/v1/invoice-approvals/matrices/{id}',
        operationId: 'deleteInvoiceApprovalMatrix',
        description: 'Deactivate an approval rule. Invoices already waiting keep waiting.',
        summary: 'Delete approval matrix',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Supplier Invoice Approval'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Matrix deactivated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroyMatrix(Request $request, string $id): JsonResponse
    {
        ApprovalMatrix::query()->where('target_entity_type', PassiveInvoice::class)->findOrFail($id)->update(['is_active' => false]);

        return $this->successResponse(null, 'Matrix deactivated');
    }
}
