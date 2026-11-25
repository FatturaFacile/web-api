<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Treasury\Http\Requests\PaymentFlowRequest;
use Modules\Treasury\Http\Resources\PaymentBatchResource;
use Modules\Treasury\Services\PaymentFlows\PaymentFlow;
use Modules\Treasury\Services\PaymentFlows\PaymentFlowService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

final class PaymentFlowController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PaymentFlowService $flows) {}

    #[OA\Get(
        path: '/api/v1/treasury/payment-flows',
        operationId: 'listTreasuryPaymentFlows',
        description: 'The outgoing payment file flows (Swiss pain.001.001.09, SEPA pain.001.001.03, CBI) with version, currencies and whether a CUC is required.',
        summary: 'List payment flows',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Payment Flows'],
        responses: [new OA\Response(response: 200, description: 'Flows', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))]
    )]
    public function index(): JsonResponse
    {
        return $this->successResponse(array_map(static fn (PaymentFlow $flow): array => [
            'flow' => $flow->value,
            'pain_version' => $flow->version()->value,
            'currencies' => $flow->currencies(),
            'requires_cuc' => $flow->requiresCuc(),
            'is_sepa' => $flow->isSepa(),
        ], PaymentFlow::cases()));
    }

    #[OA\Post(
        path: '/api/v1/treasury/payment-flows/preview',
        operationId: 'previewTreasuryPaymentFlow',
        description: 'Validates the payments against the rules of the flow and returns the XSD-validated XML file. Nothing is stored.',
        summary: 'Preview a payment file',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        tags: ['Treasury Payment Flows'],
        responses: [
            new OA\Response(response: 200, description: 'Generated file', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function preview(PaymentFlowRequest $request): JsonResponse
    {
        $data = $request->validated();
        $generated = $this->flows->generate(PaymentFlow::from($data['flow']), $data['bank_account_id'], $data['execution_date'], $data['lines'], $data['cuc'] ?? null, $data['currency'] ?? null);

        return $this->successResponse($generated->toArray());
    }

    #[OA\Post(
        path: '/api/v1/treasury/payment-flows/batches',
        operationId: 'createTreasuryPaymentFlowBatch',
        description: 'Validates the payments and stores them as a draft payment batch that follows the approval, export and bank confirmation workflow.',
        summary: 'Create a payment batch from a flow',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        tags: ['Treasury Payment Flows'],
        responses: [
            new OA\Response(response: 201, description: 'Draft batch', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function storeBatch(PaymentFlowRequest $request): JsonResponse
    {
        $data = $request->validated();
        $batch = $this->flows->createBatch(PaymentFlow::from($data['flow']), $data['bank_account_id'], $data['execution_date'], $data['lines'], $data['cuc'] ?? null, $data['currency'] ?? null, (string) $request->user()->id);

        return $this->successResponse(new PaymentBatchResource($batch), __('treasury_flows.messages.batch_created'), Response::HTTP_CREATED);
    }
}
