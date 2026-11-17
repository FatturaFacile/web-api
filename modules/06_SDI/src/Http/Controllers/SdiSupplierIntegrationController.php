<?php

declare(strict_types=1);

namespace Modules\SDI\Http\Controllers;

use App\Actions\Sdi\CreateSelfInvoice;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Http\Responses\ApiResponse;
use App\Models\PassiveInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Modules\SDI\Http\Requests\GenerateIntegrationRequest;
use Modules\SDI\Services\IntegrationDocumentTypeDetector;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class SdiSupplierIntegrationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly IntegrationDocumentTypeDetector $detector,
        private readonly CreateSelfInvoice $selfInvoice,
    ) {}

    #[OA\Post(
        path: '/api/v1/sdi/supplier-invoices/{invoice}/generate-integration',
        operationId: 'generateSdiSupplierInvoiceIntegration',
        summary: 'Generate the TD16/TD17/TD18/TD19 integration self-invoice of a foreign or reverse-charge supplier invoice',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['SDI'],
        parameters: [new OA\Parameter(name: 'invoice', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'Integration self-invoice created, ready for the SDI pipeline'),
            new OA\Response(response: 404, description: 'Supplier invoice not found'),
            new OA\Response(response: 422, description: 'No integration is due, or the type is already issued'),
        ]
    )]
    public function __invoke(GenerateIntegrationRequest $request, string $invoice): JsonResponse
    {
        $source = PassiveInvoice::query()->with('supplier')->findOrFail($invoice);
        $type = $this->detector->detect($source, $request->validated('supply_kind'));

        if ($type === null) {
            $message = $this->detector->isCandidate($source)
                ? __('sdi.fiscal.integration_supply_kind_required')
                : __('sdi.fiscal.integration_not_due');

            throw ValidationException::withMessages(['supply_kind' => $message]);
        }

        $created = $this->selfInvoice->execute($source, [
            'document_type' => $type->value,
            'date' => $request->validated('date') ?? now()->toDateString(),
            'tax_rate' => $request->validated('tax_rate') ?? 22,
            'vat_nature' => $request->validated('vat_nature'),
            'description' => $request->validated('description'),
            'notes' => $request->validated('notes'),
        ]);

        return $this->successResponse(new InvoiceResource($created), __('sdi.messages.self_invoice_created'), Response::HTTP_CREATED);
    }
}
