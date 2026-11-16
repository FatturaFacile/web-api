<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Customs\GenerateInvoiceOriginDeclarationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customs\EvaluateCustomsOriginRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class InvoiceCustomsOriginController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly GenerateInvoiceOriginDeclarationAction $generate) {}

    #[OA\Post(
        path: '/api/v1/sales/invoices/{id}/evaluate-customs-origin',
        operationId: 'evaluateInvoiceCustomsOrigin',
        description: 'Evaluates the preferential origin of a cross-border invoice and renders the origin declaration. With `apply=true` the result is stored on the invoice, otherwise it is only a preview.',
        summary: 'Evaluate customs origin',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Customs'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'locale', type: 'string', enum: ['it', 'de', 'fr', 'en'], nullable: true),
                new OA\Property(property: 'customs_auth_number', type: 'string', nullable: true),
                new OA\Property(property: 'apply', type: 'boolean', example: false),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Customs origin evaluated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Invoice not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function evaluate(EvaluateCustomsOriginRequest $request, string $id): JsonResponse
    {
        $invoice = Invoice::with(['items', 'contact', 'tenant'])->findOrFail($id);
        $apply = $request->boolean('apply');
        $this->authorize($apply ? 'update' : 'view', $invoice);

        $result = $this->generate->execute(
            invoice: $invoice,
            locale: $request->input('locale'),
            authorizationOverride: $request->input('customs_auth_number'),
            persist: $apply,
        );

        return $this->successResponse(
            data: [
                ...$result['evaluation']->toArray(),
                'locale' => $result['locale'],
                'declaration_text' => $result['declaration_text'],
                'applied' => $apply,
            ],
            message: __('api.customs_origin_evaluated'),
        );
    }
}
