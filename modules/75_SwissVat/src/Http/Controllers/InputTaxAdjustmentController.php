<?php

declare(strict_types=1);

namespace Modules\SwissVat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\SwissVat\Http\Controllers\Concerns\ResolvesSwissVatContext;
use Modules\SwissVat\Http\Requests\EmptySwissVatActionRequest;
use Modules\SwissVat\Http\Requests\StoreInputTaxAdjustmentRequest;
use Modules\SwissVat\Http\Requests\UpdateInputTaxAdjustmentRequest;
use Modules\SwissVat\Http\Resources\InputTaxAdjustmentResource;
use Modules\SwissVat\Services\InputTaxAdjustmentService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * LIVA Art. 30-33 input tax adjustments: Autoconsumo, Degrassazione, Mixed-use reduction, Subsidy reduction.
 */
final class InputTaxAdjustmentController extends Controller
{
    use ApiResponse;
    use ResolvesSwissVatContext;

    public function __construct(private readonly InputTaxAdjustmentService $adjustments) {}

    #[OA\Get(path: '/api/v1/swiss-vat/adjustments', operationId: 'listSwissVatAdjustments', summary: 'List input tax adjustments', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Swiss VAT'], responses: [new OA\Response(response: 200, description: 'Input tax adjustments')])]
    public function index(Request $request): JsonResponse
    {
        $year = $request->query('year') !== null ? (int) $request->query('year') : null;
        $status = $request->query('status') ? (string) $request->query('status') : null;

        return $this->successResponse(InputTaxAdjustmentResource::collection($this->adjustments->list($this->tenantId(), $year, $status)));
    }

    #[OA\Post(path: '/api/v1/swiss-vat/adjustments', operationId: 'createSwissVatAdjustment', summary: 'Create an input tax adjustment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Swiss VAT'], responses: [new OA\Response(response: 201, description: 'Adjustment created')])]
    public function store(StoreInputTaxAdjustmentRequest $request): JsonResponse
    {
        $adjustment = $this->adjustments->create($this->tenantId(), $this->userId($request), $request->validated());

        return $this->successResponse(new InputTaxAdjustmentResource($adjustment), __('swiss_vat.messages.adjustment_created'), Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/swiss-vat/adjustments/{id}', operationId: 'updateSwissVatAdjustment', summary: 'Update an input tax adjustment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Swiss VAT'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Adjustment updated')])]
    public function update(UpdateInputTaxAdjustmentRequest $request, string $id): JsonResponse
    {
        $adjustment = $this->adjustments->update($this->tenantId(), $id, $request->validated());

        return $this->successResponse(new InputTaxAdjustmentResource($adjustment), __('swiss_vat.messages.adjustment_updated'));
    }

    #[OA\Post(path: '/api/v1/swiss-vat/adjustments/{id}/approve', operationId: 'approveSwissVatAdjustment', summary: 'Approve an input tax adjustment for inclusion in returns', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Swiss VAT'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Adjustment approved')])]
    public function approve(EmptySwissVatActionRequest $request, string $id): JsonResponse
    {
        $adjustment = $this->adjustments->approve($this->tenantId(), $id, $this->userId($request));

        return $this->successResponse(new InputTaxAdjustmentResource($adjustment), __('swiss_vat.messages.adjustment_approved'));
    }

    #[OA\Delete(path: '/api/v1/swiss-vat/adjustments/{id}', operationId: 'deleteSwissVatAdjustment', summary: 'Delete a draft or approved input tax adjustment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Swiss VAT'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Adjustment deleted')])]
    public function destroy(string $id): JsonResponse
    {
        $this->adjustments->delete($this->tenantId(), $id);

        return $this->successResponse(null, __('swiss_vat.messages.adjustment_deleted'));
    }
}
