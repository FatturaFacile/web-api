<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\GeneralLedger\Models\CustomerLedger;
use Modules\GeneralLedger\Models\SupplierLedger;
use OpenApi\Attributes as OA;

final class SubledgerController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Returns the customer subledger items of the active tenant ordered by due date, optionally filtered by customer and status.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/subledgers/customers',
        operationId: 'getCustomerLedgerGeneralLedgerSubledgersCustomers',
        description: 'Returns the customer subledger items of the active tenant ordered by due date, optionally filtered by customer and status.',
        summary: 'List customer subledger',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Subledgers'],
        parameters: [
            new OA\Parameter(name: 'customer_id', in: 'query', required: false, description: 'Filter by customer', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Filter by status', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', default: 50)),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerCustomerLedgerModel')), new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'per_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function customerLedger(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        $items = CustomerLedger::where('tenant_id', $tenantId)
            ->when($request->query('customer_id'), fn ($q, $cid) => $q->where('customer_id', $cid))
            ->when($request->query('status'), fn ($q, $st) => $q->where('status', $st))
            ->orderBy('due_date')
            ->paginate((int) $request->query('per_page', 50));

        return response()->json([
            'data' => $items,
        ]);
    }

    /**
     * Returns the supplier subledger items of the active tenant ordered by due date, optionally filtered by supplier and status.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/subledgers/suppliers',
        operationId: 'getSupplierLedgerGeneralLedgerSubledgersSuppliers',
        description: 'Returns the supplier subledger items of the active tenant ordered by due date, optionally filtered by supplier and status.',
        summary: 'List supplier subledger',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Subledgers'],
        parameters: [
            new OA\Parameter(name: 'supplier_id', in: 'query', required: false, description: 'Filter by supplier', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Filter by status', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', default: 50)),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerSupplierLedgerModel')), new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'per_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function supplierLedger(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        $items = SupplierLedger::where('tenant_id', $tenantId)
            ->when($request->query('supplier_id'), fn ($q, $sid) => $q->where('supplier_id', $sid))
            ->when($request->query('status'), fn ($q, $st) => $q->where('status', $st))
            ->orderBy('due_date')
            ->paginate((int) $request->query('per_page', 50));

        return response()->json([
            'data' => $items,
        ]);
    }
}
