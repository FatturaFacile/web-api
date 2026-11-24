<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sales\ImportBankPayments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\ImportPaymentsRequest;
use App\Http\Requests\Sales\MatchImportLineRequest;
use App\Http\Resources\CatalogResource;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use App\Models\PaymentImport;
use App\Models\PaymentImportLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class PaymentImportController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ImportBankPayments $importPayments) {}

    #[OA\Get(
        path: '/api/v1/sales/payment-imports',
        operationId: 'getPaymentImports',
        description: 'List paginated e-banking camt.053/camt.054 payment imports.',
        summary: 'List payment imports',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Sales', 'Payments'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Payment imports retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(5, (int) $request->query('per_page', 15)));
        $imports = PaymentImport::query()->latest()->gridPaginate($perPage);

        return $this->successResponse(
            data: CatalogResource::collection($imports),
            message: __('sales.messages.payment_imports_list'),
            meta: ['current_page' => $imports->currentPage(), 'last_page' => $imports->lastPage(), 'per_page' => $imports->perPage(), 'total' => $imports->total()]
        );
    }

    #[OA\Get(
        path: '/api/v1/sales/payment-imports/{id}',
        operationId: 'getPaymentImport',
        description: 'Get details and statement lines for a specific payment import batch.',
        summary: 'Get payment import',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Sales', 'Payments'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Payment import retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Payment import not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new CatalogResource(PaymentImport::with('lines')->findOrFail($id)), __('sales.messages.payment_import_retrieved'));
    }

    /**
     * Upload an e-banking camt.053/camt.054 file and auto-match its credits to open invoices.
     */
    #[OA\Post(
        path: '/api/v1/sales/payment-imports',
        operationId: 'storePaymentImport',
        description: 'Upload an ISO 20022 camt.053/camt.054 XML statement file and auto-match credits.',
        summary: 'Upload payment import file',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Sales', 'Payments'],
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
            new OA\Response(response: 201, description: 'Payments imported successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid file format', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(ImportPaymentsRequest $request): JsonResponse
    {
        $file = $request->file('file');

        try {
            $import = $this->importPayments->execute((string) file_get_contents($file->getRealPath()), $file->getClientOriginalName());
        } catch (InvalidArgumentException $e) {
            return $this->problemDetailsResponse(
                title: __('bank.invalid_file_title'),
                detail: $e->getMessage(),
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return $this->successResponse(new CatalogResource($import), __('sales.messages.payments_imported'), Response::HTTP_CREATED);
    }

    #[OA\Post(
        path: '/api/v1/sales/payment-imports/{id}/lines/{lineId}/match',
        operationId: 'matchPaymentImportLine',
        description: 'Manually reconcile a payment import statement line against an open invoice.',
        summary: 'Manual match payment line',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Sales', 'Payments'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'lineId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['invoice_id'],
                properties: [
                    new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Line matched successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Record not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function matchLine(MatchImportLineRequest $request, string $id, string $lineId): JsonResponse
    {
        $line = PaymentImportLine::where('payment_import_id', $id)->findOrFail($lineId);
        $line = $this->importPayments->matchManually($line, Invoice::findOrFail($request->validated('invoice_id')));

        return $this->successResponse(new CatalogResource($line), __('sales.messages.line_matched'));
    }
}
