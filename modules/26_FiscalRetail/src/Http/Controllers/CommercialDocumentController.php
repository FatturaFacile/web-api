<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PosSale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\FiscalRetail\Actions\IssueCommercialDocument;
use Modules\FiscalRetail\Http\Requests\StoreCommercialDocumentRequest;
use Modules\FiscalRetail\Models\CommercialDocument;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

final class CommercialDocumentController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/fiscal-retail/commercial-documents',
        operationId: 'listCommercialDocuments',
        description: 'Commercial documents of the active tenant, newest first. Filter with `document_type`, `from` and `to`.',
        summary: 'List commercial documents',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Fiscal Retail'],
        parameters: [
            new OA\Parameter(name: 'document_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['sale', 'void', 'return'])),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated list', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_type' => ['nullable', 'in:sale,void,return'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $documents = CommercialDocument::query()
            ->when($validated['document_type'] ?? null, static fn ($query, string $type) => $query->where('document_type', $type))
            ->when($validated['from'] ?? null, static fn ($query, string $from) => $query->whereDate('issue_date', '>=', $from))
            ->when($validated['to'] ?? null, static fn ($query, string $to) => $query->whereDate('issue_date', '<=', $to))
            ->orderByDesc('fiscal_year')
            ->orderByDesc('sequential_number')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return $this->successResponse($documents->items(), 'Commercial documents retrieved', meta: [
            'total' => $documents->total(), 'current_page' => $documents->currentPage(), 'last_page' => $documents->lastPage(),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/fiscal-retail/commercial-documents',
        operationId: 'issueCommercialDocument',
        description: 'Issues a numbered commercial document, from explicit lines or from a POS sale. With the local driver the status is `simulated`: nothing is transmitted to the Agenzia delle Entrate.',
        summary: 'Issue commercial document',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'pos_sale_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'payment_method', type: 'string', nullable: true),
            new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object', properties: [
                new OA\Property(property: 'description', type: 'string'),
                new OA\Property(property: 'quantity', type: 'number'),
                new OA\Property(property: 'unit_price', type: 'number'),
                new OA\Property(property: 'tax_rate', type: 'number'),
                new OA\Property(property: 'vat_nature', type: 'string', nullable: true),
            ])),
        ])),
        tags: ['Fiscal Retail'],
        responses: [
            new OA\Response(response: 201, description: 'Document issued', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 422, description: 'Invalid data, closed day or document quota exceeded', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreCommercialDocumentRequest $request, IssueCommercialDocument $issue): JsonResponse
    {
        $data = $request->validated();

        $document = isset($data['pos_sale_id'])
            ? $issue->fromPosSale(PosSale::findOrFail($data['pos_sale_id']))
            : $issue->sale($data['lines'], $data['payment_method'] ?? null);

        return $this->successResponse($document->load('lines'), 'Commercial document issued', Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/fiscal-retail/commercial-documents/{id}',
        operationId: 'getCommercialDocument',
        description: 'A commercial document with its lines.',
        summary: 'Get commercial document',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Fiscal Retail'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The document', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(CommercialDocument::with('lines')->findOrFail($id), 'Commercial document retrieved');
    }

    #[OA\Post(
        path: '/api/v1/fiscal-retail/commercial-documents/{id}/void',
        operationId: 'voidCommercialDocument',
        description: 'Issues a numbered void document for a whole sale document that has not been corrected yet.',
        summary: 'Void commercial document',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Fiscal Retail'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'Void document issued', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Already corrected, or not a sale', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function void(string $id, IssueCommercialDocument $issue): JsonResponse
    {
        return $this->successResponse($issue->void(CommercialDocument::findOrFail($id)), 'Void document issued', Response::HTTP_CREATED);
    }

    #[OA\Post(
        path: '/api/v1/fiscal-retail/commercial-documents/{id}/return',
        operationId: 'returnCommercialDocument',
        description: 'Issues a numbered return document. Without `lines` every quantity still open is returned.',
        summary: 'Return goods of a commercial document',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object', properties: [
                new OA\Property(property: 'line_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'quantity', type: 'number'),
            ])),
        ])),
        tags: ['Fiscal Retail'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'Return document issued', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Quantity above what is still open', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function return(Request $request, string $id, IssueCommercialDocument $issue): JsonResponse
    {
        $validated = $request->validate([
            'lines' => ['nullable', 'array'],
            'lines.*.line_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        return $this->successResponse($issue->return(CommercialDocument::findOrFail($id), $validated['lines'] ?? []), 'Return document issued', Response::HTTP_CREATED);
    }
}
