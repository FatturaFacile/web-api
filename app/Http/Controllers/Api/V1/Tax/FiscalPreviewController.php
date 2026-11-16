<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Tax;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tax\FiscalPreviewRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use App\Services\Invoicing\InvoiceService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Italian fiscal preview of an unsaved invoice: the same calculation that invoice creation persists.
 */
class FiscalPreviewController extends Controller
{
    use ApiResponse, AuthorizesRequests;

    public function __construct(private readonly InvoiceService $invoiceService) {}

    #[OA\Post(
        path: '/api/v1/tax/preview',
        operationId: 'previewItalianFiscal',
        description: 'Compute cassa, VAT summary, ritenuta, ENASARCO, virtual stamp duty, split payment and amount due of an unsaved Italian invoice. Amounts are decimal strings with two decimals. `fund`, `withholding`, `enasarco` and `stamp_duty_charged_to_customer`: omitted = tenant default, null = disabled.',
        summary: 'Preview Italian invoice fiscal figures',
        security: [['bearerAuth' => []]],
        tags: ['Tax', 'Invoices'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['items'],
                properties: [
                    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'date', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'split_payment', type: 'boolean', nullable: true),
                    new OA\Property(property: 'reverse_charge', type: 'boolean', nullable: true),
                    new OA\Property(property: 'vat_payability', type: 'string', enum: ['I', 'D', 'S'], nullable: true),
                    new OA\Property(property: 'fund', type: 'object', nullable: true, example: ['type' => 'TC22', 'percent' => 4, 'vat_rate' => 22, 'natura' => null, 'withholding_applies' => false]),
                    new OA\Property(property: 'withholding', type: 'object', nullable: true, example: ['type' => 'RT01', 'reason' => 'A', 'rate' => 20, 'taxable_percent' => 100, 'includes_fund' => false]),
                    new OA\Property(property: 'enasarco', type: 'object', nullable: true, example: ['enabled' => true, 'rate' => null, 'reason' => 'R']),
                    new OA\Property(property: 'stamp_duty_charged_to_customer', type: 'boolean', nullable: true),
                    new OA\Property(
                        property: 'items',
                        type: 'array',
                        items: new OA\Items(
                            required: ['quantity', 'unit_price', 'tax_rate'],
                            properties: [
                                new OA\Property(property: 'description', type: 'string'),
                                new OA\Property(property: 'quantity', type: 'number', example: 1),
                                new OA\Property(property: 'unit_price', type: 'number', example: 1000),
                                new OA\Property(property: 'tax_rate', type: 'number', example: 22),
                                new OA\Property(property: 'discount_percentage', type: 'number', nullable: true),
                                new OA\Property(property: 'natura', type: 'string', nullable: true, example: 'N4'),
                                new OA\Property(property: 'withholding_applies', type: 'boolean', nullable: true),
                                new OA\Property(property: 'fund_applies', type: 'boolean', nullable: true),
                            ]
                        )
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Fiscal figures calculated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error or incompatible fiscal options (code forfettario_incompatible, enasarco_rate_not_configured, split_payment_incompatible, split_payment_requires_vat)', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function __invoke(FiscalPreviewRequest $request): JsonResponse
    {
        $this->authorize('viewTaxReports', Invoice::class);

        return $this->successResponse(
            $this->invoiceService->previewItalianFiscal($request->validated())->toArray(),
            __('tax.messages.preview'),
        );
    }
}
