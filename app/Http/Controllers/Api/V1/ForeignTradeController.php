<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Incoterm;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customs\CustomsValueRequest;
use App\Http\Requests\Customs\VatRegimeRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use App\Services\ForeignTrade\CrossBorderReadinessValidator;
use App\Services\ForeignTrade\CrossBorderVatRegimeResolver;
use App\Services\ForeignTrade\CustomsValueCalculator;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class ForeignTradeController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CrossBorderReadinessValidator $readiness,
        private readonly CustomsValueCalculator $customsValue,
        private readonly CrossBorderVatRegimeResolver $regimes,
    ) {}

    #[OA\Get(path: '/api/v1/sales/invoices/{id}/cross-border-readiness', operationId: 'invoiceCrossBorderReadiness', description: 'Checks the Incoterm and, per goods line, the tariff code, country of origin and net weight, and returns the VAT regime of the cross-border supply.', summary: 'Cross-border readiness of an invoice', security: [['bearerAuth' => []], ['tenantHeader' => []]], tags: ['Customs'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Readiness', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Invoice not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function readiness(string $id): JsonResponse
    {
        $invoice = Invoice::query()->with('items')->findOrFail($id);
        $this->authorize('view', $invoice);

        return $this->successResponse(data: $this->readiness->checkInvoice($invoice), message: __('api.customs_origin_evaluated'));
    }

    #[OA\Post(path: '/api/v1/foreign-trade/customs-value', operationId: 'foreignTradeCustomsValue', description: 'Computes the customs and statistical value of a consignment: carriage and insurance are added when the Incoterm leaves them outside the price.', summary: 'Customs value by Incoterm', security: [['bearerAuth' => []], ['tenantHeader' => []]], tags: ['Customs'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['incoterm', 'goods_value'], properties: [
        new OA\Property(property: 'incoterm', type: 'string', enum: ['EXW', 'FCA', 'CPT', 'CIP', 'DAP', 'DPU', 'DDP', 'FAS', 'FOB', 'CFR', 'CIF']),
        new OA\Property(property: 'goods_value', type: 'number'),
        new OA\Property(property: 'freight', type: 'number', nullable: true),
        new OA\Property(property: 'insurance', type: 'number', nullable: true),
    ])), responses: [
        new OA\Response(response: 200, description: 'Customs value', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function customsValue(CustomsValueRequest $request): JsonResponse
    {
        $value = $this->customsValue->calculate(
            Incoterm::from((string) $request->validated('incoterm')),
            (float) $request->validated('goods_value'),
            (float) $request->validated('freight', 0),
            (float) $request->validated('insurance', 0),
        );

        return $this->successResponse(data: $value->toArray(), message: __('api.customs_origin_evaluated'));
    }

    #[OA\Post(path: '/api/v1/foreign-trade/vat-regime', operationId: 'foreignTradeVatRegime', description: 'Resolves the VAT regime (CH export art. 23 LTVA, IT intra-EU art. 41 DL 331/93, IT export art. 8 DPR 633/72, reverse charge, import) from the two countries.', summary: 'Cross-border VAT regime', security: [['bearerAuth' => []], ['tenantHeader' => []]], tags: ['Customs'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['tenant_country', 'counterparty_country'], properties: [
        new OA\Property(property: 'tenant_country', type: 'string'),
        new OA\Property(property: 'counterparty_country', type: 'string'),
        new OA\Property(property: 'counterparty_vat_taxable', type: 'boolean'),
        new OA\Property(property: 'is_purchase', type: 'boolean'),
    ])), responses: [
        new OA\Response(response: 200, description: 'Regime', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function vatRegime(VatRegimeRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->successResponse(
            data: $this->regimes->resolve(
                (string) $data['tenant_country'],
                (string) $data['counterparty_country'],
                (bool) ($data['counterparty_vat_taxable'] ?? true),
                (bool) ($data['is_purchase'] ?? false),
            ),
            message: __('api.customs_origin_evaluated'),
        );
    }
}
