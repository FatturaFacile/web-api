<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Purchases\ReceivePassiveInvoice;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchases\UploadPassiveInvoiceRequest;
use App\Http\Resources\CatalogResource;
use App\Http\Responses\ApiResponse;
use App\Models\PassiveInvoice;
use App\Services\Sales\DocumentEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

#[OA\Tag(name: 'Supplier Invoices', description: 'Supplier invoices, passive SDI XML receiving, and payment status')]
class SupplierInvoiceController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ReceivePassiveInvoice $receive,
        private readonly DocumentEventRecorder $events,
    ) {}

    /**
     * List received supplier documents. Filters: `supplier_id`, `document_type`, `payment_status`.
     */
    #[OA\Get(
        path: '/api/v1/purchases/supplier-invoices',
        summary: 'List supplier invoices',
        description: 'Get paginated list of received supplier documents with optional filters',
        tags: ['Supplier Invoices'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'supplier_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'document_type', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'payment_status', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Supplier invoices retrieved successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $invoices = PassiveInvoice::query()
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->query('supplier_id')))
            ->when($request->filled('document_type'), fn ($q) => $q->where('document_type', $request->query('document_type')))
            ->when($request->filled('payment_status'), fn ($q) => $q->where('payment_status', $request->query('payment_status')))
            ->latest('date')
            ->paginate(min(100, max(5, (int) $request->query('per_page', 15))));

        return $this->successResponse(
            data: CatalogResource::collection($invoices),
            message: __('purchases.messages.supplier_invoices'),
            meta: ['current_page' => $invoices->currentPage(), 'last_page' => $invoices->lastPage(), 'per_page' => $invoices->perPage(), 'total' => $invoices->total()]
        );
    }

    #[OA\Get(
        path: '/api/v1/purchases/supplier-invoices/{id}',
        summary: 'Get supplier invoice',
        description: 'Retrieve details for a single supplier invoice',
        tags: ['Supplier Invoices'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Supplier invoice details retrieved'),
            new OA\Response(response: 404, description: 'Supplier invoice not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new CatalogResource(PassiveInvoice::findOrFail($id)), __('purchases.messages.supplier_invoice'));
    }

    /**
     * Upload a FatturaPA XML received from SDI and register its documents.
     */
    #[OA\Post(
        path: '/api/v1/purchases/passive-invoices/receive-xml',
        summary: 'Receive passive SDI XML',
        description: 'Upload a FatturaPA XML received from SDI and register documents',
        tags: ['Supplier Invoices'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['file'],
                    properties: [
                        new OA\Property(property: 'file', type: 'string', format: 'binary'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Passive XML parsed and registered'),
            new OA\Response(response: 422, description: 'Invalid XML or unprocessable document'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function receiveXml(UploadPassiveInvoiceRequest $request): JsonResponse
    {
        $file = $request->file('file');

        try {
            $results = $this->receive->execute((string) file_get_contents($file->getRealPath()), $file->getClientOriginalName());
        } catch (InvalidArgumentException $e) {
            return $this->problemDetailsResponse(
                title: __('purchases.invalid_fatturapa_title'),
                detail: $e->getMessage(),
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return $this->successResponse(
            array_map(fn (array $result): array => [
                'duplicate' => $result['duplicate'],
                'invoice' => (new CatalogResource($result['invoice']))->resolve($request),
            ], $results),
            __('purchases.messages.invoices_received'),
            Response::HTTP_CREATED
        );
    }

    #[OA\Post(
        path: '/api/v1/purchases/supplier-invoices/{id}/mark-paid',
        summary: 'Mark supplier invoice as paid',
        description: 'Update payment status of a supplier invoice to paid',
        tags: ['Supplier Invoices'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Supplier invoice marked as paid'),
            new OA\Response(response: 404, description: 'Supplier invoice not found'),
            new OA\Response(response: 422, description: 'Invoice cannot be marked paid'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function markPaid(string $id): JsonResponse
    {
        $invoice = PassiveInvoice::findOrFail($id);

        if ($invoice->document_type === 'TD04') {
            throw ValidationException::withMessages(['invoice' => __('purchases.credit_note_not_payable')]);
        }

        if ($invoice->payment_status === 'paid') {
            throw ValidationException::withMessages(['invoice' => __('purchases.already_paid')]);
        }

        $invoice->update(['payment_status' => 'paid']);
        $this->events->record($invoice, 'marked_paid');

        return $this->successResponse(new CatalogResource($invoice), __('purchases.messages.marked_paid'));
    }
}
