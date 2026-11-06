<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Http\Requests\SaveDeclarationOfIntentRequest;
use Modules\Invoicing\Http\Resources\DeclarationOfIntentResource;
use Modules\Invoicing\Models\DeclarationOfIntent;
use Modules\Invoicing\Services\DeclarationOfIntentService;
use OpenApi\Attributes as OA;

final class DeclarationOfIntentController extends Controller
{
    public function __construct(
        private readonly DeclarationOfIntentService $declarations,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Lists the declarations of intent of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/invoicing/declarations-of-intent',
        operationId: 'listInvoicingDeclarationsOfIntent',
        description: 'Lists the declarations of intent of the active tenant: `received` from customers, `issued` to suppliers.',
        summary: 'List declarations of intent',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Declarations of Intent'],
        parameters: [
            new OA\Parameter(name: 'contact_id', in: 'query', required: false, description: 'Filter by contact', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'direction', in: 'query', required: false, description: 'Filter by direction', schema: new OA\Schema(type: 'string', enum: ['received', 'issued'])),
            new OA\Parameter(name: 'year', in: 'query', required: false, description: 'Filter by year', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InvoicingDeclarationOfIntent'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $declarations = DeclarationOfIntent::query()
            ->when($request->query('contact_id'), fn ($query, $contactId) => $query->where('contact_id', $contactId))
            ->when($request->query('direction'), fn ($query, $direction) => $query->where('direction', $direction))
            ->when($request->query('year'), fn ($query, $year) => $query->where('year', (int) $year))
            ->orderByDesc('receipt_date')
            ->get();

        return response()->json(['data' => DeclarationOfIntentResource::collection($declarations)]);
    }

    /**
     * Registers a declaration of intent.
     */
    #[OA\Post(
        path: '/api/v1/invoicing/declarations-of-intent',
        operationId: 'createInvoicingDeclarationOfIntent',
        description: 'Registers a declaration of intent. Sales to that customer within its validity are issued under nature N3.5 and consume its plafond.',
        summary: 'Create declaration of intent',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/InvoicingSaveDeclarationOfIntentRequest')),
        tags: ['Invoicing Declarations of Intent'],
        responses: [
            new OA\Response(response: 201, description: 'Declaration created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/InvoicingDeclarationOfIntent')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function store(SaveDeclarationOfIntentRequest $request): JsonResponse
    {
        $declaration = DeclarationOfIntent::create([
            ...$request->validated(),
            'tenant_id' => $this->tenantContext->getTenantId(),
            'direction' => $request->validated('direction') ?? DeclarationOfIntent::RECEIVED,
        ]);

        return response()->json(['data' => new DeclarationOfIntentResource($declaration->refresh())], 201);
    }

    /**
     * Returns the yearly summary of the declarations of intent.
     */
    #[OA\Get(
        path: '/api/v1/invoicing/declarations-of-intent/summary',
        operationId: 'getInvoicingDeclarationsOfIntentSummary',
        description: 'Returns the declarations of a year with plafond, consumption and residual, and the totals per direction.',
        summary: 'Get declarations of intent summary',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Declarations of Intent'],
        parameters: [
            new OA\Parameter(name: 'year', in: 'query', required: true, description: 'Year of the declarations', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:2100']]);

        return response()->json(['data' => $this->declarations->annualSummary((string) $this->tenantContext->getTenantId(), (int) $validated['year'])]);
    }

    /**
     * Returns a declaration of intent.
     */
    #[OA\Get(
        path: '/api/v1/invoicing/declarations-of-intent/{id}',
        operationId: 'getInvoicingDeclarationOfIntent',
        description: 'Returns a declaration of intent of the active tenant.',
        summary: 'Get declaration of intent',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Declarations of Intent'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the declaration', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/InvoicingDeclarationOfIntent')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => new DeclarationOfIntentResource(DeclarationOfIntent::findOrFail($id))]);
    }

    /**
     * Updates a declaration of intent. Its consumption is not editable.
     */
    #[OA\Put(
        path: '/api/v1/invoicing/declarations-of-intent/{id}',
        operationId: 'updateInvoicingDeclarationOfIntent',
        description: 'Updates a declaration of intent. The consumption follows from the documents and cannot be set, and the plafond cannot go below it.',
        summary: 'Update declaration of intent',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/InvoicingSaveDeclarationOfIntentRequest')),
        tags: ['Invoicing Declarations of Intent'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the declaration', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/InvoicingDeclarationOfIntent')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function update(SaveDeclarationOfIntentRequest $request, string $id): JsonResponse
    {
        $declaration = DeclarationOfIntent::findOrFail($id);

        if (Decimal::compare((string) $request->validated('plafond_amount'), (string) $declaration->consumed_amount) < 0) {
            throw ValidationException::withMessages(['plafond_amount' => __('sdi.fiscal.declaration_over_plafond', ['protocol' => $declaration->protocol_number, 'residual' => '0.00'])]);
        }

        $declaration->update($request->validated());

        return response()->json(['data' => new DeclarationOfIntentResource($declaration)]);
    }

    /**
     * Deletes a declaration of intent no document has used.
     */
    #[OA\Delete(
        path: '/api/v1/invoicing/declarations-of-intent/{id}',
        operationId: 'deleteInvoicingDeclarationOfIntent',
        description: 'Deletes a declaration of intent no document has used.',
        summary: 'Delete declaration of intent',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Declarations of Intent'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the declaration', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Declaration deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'The declaration was used by documents', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $declaration = DeclarationOfIntent::findOrFail($id);

        if (Decimal::isPositive((string) $declaration->consumed_amount)) {
            throw ValidationException::withMessages(['declaration' => __('sdi.fiscal.declaration_in_use')]);
        }

        $declaration->delete();

        return response()->json(null, 204);
    }
}
