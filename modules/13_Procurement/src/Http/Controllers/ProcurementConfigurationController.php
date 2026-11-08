<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Procurement\Http\Controllers\Concerns\ProcurementControllerHelpers;
use Modules\Procurement\Http\Requests\StoreProcurementApprovalMatrixRequest;
use Modules\Procurement\Http\Requests\UpdateProcurementSettingsRequest;
use Modules\Procurement\Models\ProcurementSetting;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Services\ProcurementApprovalService;
use Modules\Procurement\Services\ProcurementSettingsService;
use Modules\Workflow\Models\ApprovalMatrix;
use Modules\Workflow\Models\ApprovalMatrixRule;
use Modules\Workflow\Models\ApprovalMatrixTier;
use OpenApi\Attributes as OA;

/**
 * Procurement configuration of the company: receipt and matching tolerances, and the approval matrices (levels by
 * amount and cost centre) of purchase orders and requisitions.
 */
#[OA\Tag(name: 'Procurement Configuration', description: 'Tolerances and approval matrices of purchasing')]
final class ProcurementConfigurationController extends Controller
{
    use ProcurementControllerHelpers;

    public function __construct(
        private readonly ProcurementSettingsService $settings,
        private readonly ProcurementApprovalService $approvals,
    ) {}

    /**
     * Tolerances of the company.
     */
    #[OA\Get(
        path: '/api/v1/procurement/settings',
        operationId: 'procurementSettingsShow',
        description: 'Over and under receipt tolerance, invoice price tolerance (percentage and absolute amount), whether a mismatching supplier invoice is blocked for payment, and the clearing account of landed costs.',
        summary: 'Show procurement settings',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement Configuration'],
        responses: [
            new OA\Response(response: 200, description: 'Settings', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProcurementSettingsResource')])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
        ]
    )]
    public function showSettings(): JsonResponse
    {
        return response()->json(['data' => $this->present($this->settings->forTenant($this->tenantId()))]);
    }

    /**
     * Updates the tolerances.
     */
    #[OA\Put(
        path: '/api/v1/procurement/settings',
        operationId: 'procurementSettingsUpdate',
        description: 'Updates the tolerances. Percentages are between 0 and 100; zero means no tolerance.',
        summary: 'Update procurement settings',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProcurementSettingsResource')),
        tags: ['Procurement Configuration'],
        responses: [
            new OA\Response(response: 200, description: 'Settings updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProcurementSettingsResource')])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ProcUnprocessable', response: 422),
        ]
    )]
    public function updateSettings(UpdateProcurementSettingsRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->present($this->settings->update($this->tenantId(), $request->validated()))]);
    }

    /**
     * Approval matrices of orders and requisitions.
     */
    #[OA\Get(
        path: '/api/v1/procurement/approval-matrices',
        operationId: 'procurementApprovalMatricesIndex',
        description: 'Active approval matrices of purchase orders and requisitions with their levels.',
        summary: 'List procurement approval matrices',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement Configuration'],
        responses: [
            new OA\Response(response: 200, description: 'Matrices', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProcurementApprovalMatrixResource'))])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
        ]
    )]
    public function matrices(): JsonResponse
    {
        $matrices = ApprovalMatrix::query()
            ->where('tenant_id', $this->tenantId())
            ->whereIn('target_entity_type', [PurchaseOrder::class, PurchaseRequisition::class])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $matrices->map(fn (ApprovalMatrix $matrix): array => $this->presentMatrix($matrix))->all()]);
    }

    /**
     * Creates an approval matrix.
     */
    #[OA\Post(
        path: '/api/v1/procurement/approval-matrices',
        operationId: 'procurementApprovalMatricesStore',
        description: 'Levels of approval by amount (gross total of the order, estimated total of the requisition), optionally restricted to a cost centre. A matrix for a cost centre wins over a general one. Below every threshold no approval is needed.',
        summary: 'Create procurement approval matrix',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['target', 'name', 'tiers'], properties: [
            new OA\Property(property: 'target', type: 'string', enum: ['purchase_order', 'purchase_requisition']),
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'cost_center_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'tiers', type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'tier_level', type: 'integer'),
                new OA\Property(property: 'approver_type', type: 'string', enum: ['role', 'user']),
                new OA\Property(property: 'approver_value', type: 'string'),
                new OA\Property(property: 'threshold_min', type: 'string', nullable: true),
                new OA\Property(property: 'threshold_max', type: 'string', nullable: true),
            ])),
        ])),
        tags: ['Procurement Configuration'],
        responses: [
            new OA\Response(response: 201, description: 'Matrix created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProcurementApprovalMatrixResource')])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ProcUnprocessable', response: 422),
        ]
    )]
    public function storeMatrix(StoreProcurementApprovalMatrixRequest $request): JsonResponse
    {
        /** @var array{target: string, name: string, cost_center_id?: ?string, tiers: list<array{tier_level: int, approver_type: string, approver_value: string, threshold_min?: string|null, threshold_max?: string|null}>} $data */
        $data = $request->validated();

        return response()->json(['data' => $this->presentMatrix($this->approvals->createMatrix($this->tenantId(), $data))], 201);
    }

    /**
     * Deactivates an approval matrix.
     */
    #[OA\Delete(
        path: '/api/v1/procurement/approval-matrices/{id}',
        operationId: 'procurementApprovalMatricesDestroy',
        description: 'Removes the matrix; workflows already running keep their levels.',
        summary: 'Delete procurement approval matrix',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement Configuration'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProcRecordId')],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ProcNotFound', response: 404),
        ]
    )]
    public function destroyMatrix(string $id): Response
    {
        $matrix = ApprovalMatrix::query()
            ->where('tenant_id', $this->tenantId())
            ->whereIn('target_entity_type', [PurchaseOrder::class, PurchaseRequisition::class])
            ->findOrFail($id);

        $matrix->update(['is_active' => false]);
        $matrix->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ProcurementSetting $settings): array
    {
        return [
            'over_receipt_tolerance_percent' => (string) $settings->over_receipt_tolerance_percent,
            'under_receipt_tolerance_percent' => (string) $settings->under_receipt_tolerance_percent,
            'price_tolerance_percent' => (string) $settings->price_tolerance_percent,
            'price_tolerance_amount' => (string) $settings->price_tolerance_amount,
            'block_invoice_on_mismatch' => (bool) $settings->block_invoice_on_mismatch,
            'landed_cost_clearing_account_code' => (string) $settings->landed_cost_clearing_account_code,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentMatrix(ApprovalMatrix $matrix): array
    {
        return [
            'id' => (string) $matrix->id,
            'name' => $matrix->name,
            'target' => array_search($matrix->target_entity_type, ProcurementApprovalService::Targets, true) ?: null,
            'is_active' => (bool) $matrix->is_active,
            'cost_center_id' => ApprovalMatrixRule::query()->where('approval_matrix_id', $matrix->id)->whereNotNull('cost_center_id')->value('cost_center_id'),
            'tiers' => ApprovalMatrixTier::query()->where('approval_matrix_id', $matrix->id)->orderBy('tier_level')->get()->map(static fn (ApprovalMatrixTier $tier): array => [
                'tier_level' => $tier->tier_level,
                'approver_type' => $tier->approver_type,
                'approver_value' => $tier->approver_value,
                'threshold_min' => $tier->threshold_min,
                'threshold_max' => $tier->threshold_max,
            ])->all(),
        ];
    }
}
