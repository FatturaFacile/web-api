<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Http\Requests\StoreStatementSchemeRequest;
use Modules\GeneralLedger\Http\Resources\StatementSchemeResource;
use Modules\GeneralLedger\Models\StatementScheme;
use Modules\GeneralLedger\Models\StatementSchemeRow;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reclassification schemes written by the users. The application ships no statutory layout.
 */
final class StatementSchemeController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Get(
        path: '/api/v1/general-ledger/statement-schemes',
        operationId: 'listStatementSchemes',
        description: 'Reclassification schemes of the tenant.',
        summary: 'List statement schemes',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Financial Statements'],
        responses: [
            new OA\Response(response: 200, description: 'Schemes', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerStatementSchemeResource'))])),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(): JsonResponse
    {
        return $this->successResponse(StatementSchemeResource::collection(StatementScheme::query()->with('rows')->orderBy('name')->get()));
    }

    #[OA\Post(
        path: '/api/v1/general-ledger/statement-schemes',
        operationId: 'createStatementScheme',
        description: 'Create a scheme: rows that pick accounts by code prefix, headings, and subtotals of the rows above them.',
        summary: 'Create statement scheme',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerStoreStatementSchemeRequest')),
        tags: ['General Ledger Financial Statements'],
        responses: [
            new OA\Response(response: 201, description: 'Scheme created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerStatementSchemeResource')])),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreStatementSchemeRequest $request): JsonResponse
    {
        $tenantId = (string) $this->tenantContext->getTenantId();

        $scheme = DB::transaction(function () use ($request, $tenantId): StatementScheme {
            $scheme = StatementScheme::create([...$request->safe()->except('rows'), 'tenant_id' => $tenantId]);

            foreach ($request->validated('rows') as $order => $row) {
                StatementSchemeRow::create([
                    'tenant_id' => $tenantId,
                    'scheme_id' => $scheme->id,
                    'code' => $row['code'],
                    'label' => $row['label'],
                    'row_type' => $row['row_type'],
                    'sign' => $row['sign'] ?? 1,
                    'sort_order' => $order,
                    'account_prefixes' => $row['account_prefixes'] ?? [],
                    'formula' => $row['formula'] ?? [],
                ]);
            }

            return $scheme;
        });

        return $this->successResponse(new StatementSchemeResource($scheme->load('rows')), 'Scheme created', Response::HTTP_CREATED);
    }

    #[OA\Delete(
        path: '/api/v1/general-ledger/statement-schemes/{id}',
        operationId: 'deleteStatementScheme',
        description: 'Delete a scheme.',
        summary: 'Delete statement scheme',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Financial Statements'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Scheme deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        StatementScheme::query()->findOrFail($id)->delete();

        return $this->successResponse(null, 'Scheme deleted');
    }
}
