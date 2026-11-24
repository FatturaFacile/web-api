<?php

declare(strict_types=1);

namespace Modules\Logistics\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Logistics\Actions\DeclareConsignmentConsumptionAction;
use Modules\Logistics\Http\Requests\DeclareConsumptionRequest;
use Modules\Logistics\Http\Requests\StoreConsignmentAgreementRequest;
use Modules\Logistics\Http\Requests\VendorReceiptRequest;
use Modules\Logistics\Models\ConsignmentAgreement;
use Modules\Logistics\Models\ConsignmentConsumptionDeclaration;
use Modules\Logistics\Queries\InventoryValuationReportQuery;
use Modules\Logistics\Services\ConsignmentService;
use Modules\Logistics\Services\ThirdPartyStockRegisterService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'WMS Consignment', description: 'Third-party goods register, consignment agreements, periodic consumption declarations and segregated fiscal valuation. Also served under the legacy prefix /api/v1/logistics.')]
final class ConsignmentController extends Controller
{
    public function __construct(private readonly ConsignmentService $service) {}

    #[OA\Get(
        path: '/api/v1/wms/consignment/ledger',
        summary: 'Register of goods at/for third parties (per warehouse and product)',
        description: 'Requires permission delivery_notes.view.',
        tags: ['WMS Consignment'],
        parameters: [
            new OA\Parameter(name: 'warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'partner_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Success')]
    )]

    #[OA\Get(
        path: '/api/v1/logistics/consignment/ledger',
        summary: 'Register of goods at/for third parties (per warehouse and product)',
        description: 'Requires permission delivery_notes.view.',
        tags: ['WMS Consignment'],
        parameters: [
            new OA\Parameter(name: 'warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'partner_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Success')]
    )]
    public function ledger(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['sometimes', 'uuid'],
            'partner_id' => ['sometimes', 'uuid'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        return response()->json(['data' => $this->service->ledger($data['warehouse_id'] ?? null, $data['partner_id'] ?? null, $data['from'] ?? null, $data['to'] ?? null)]);
    }

    #[OA\Get(
        path: '/api/v1/wms/reports/inventory-valuation',
        summary: 'Fiscal inventory valuation split into owned on-hand, in-transit, at customer, at subcontractor and vendor consignment held',
        description: 'Requires permission delivery_notes.view.',
        tags: ['WMS Consignment'],
        responses: [new OA\Response(response: 200, description: 'Success')]
    )]

    #[OA\Get(
        path: '/api/v1/logistics/reports/inventory-valuation',
        summary: 'Fiscal inventory valuation split into owned on-hand, in-transit, at customer, at subcontractor and vendor consignment held',
        description: 'Requires permission delivery_notes.view.',
        tags: ['WMS Consignment'],
        responses: [new OA\Response(response: 200, description: 'Success')]
    )]

    #[OA\Get(
        path: '/api/v1/wms/consignment/valuation',
        summary: 'Fiscal inventory valuation split into owned on-hand, in-transit, at customer, at subcontractor and vendor consignment held',
        description: 'Requires permission delivery_notes.view.',
        tags: ['WMS Consignment'],
        responses: [new OA\Response(response: 200, description: 'Success')]
    )]

    #[OA\Get(
        path: '/api/v1/logistics/consignment/valuation',
        summary: 'Fiscal inventory valuation split into owned on-hand, in-transit, at customer, at subcontractor and vendor consignment held',
        description: 'Requires permission delivery_notes.view.',
        tags: ['WMS Consignment'],
        responses: [new OA\Response(response: 200, description: 'Success')]
    )]
    public function valuation(InventoryValuationReportQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->execute()]);
    }

    #[OA\Get(
        path: '/api/v1/wms/reports/third-party-register',
        summary: 'Third-party goods register: goods at customers, at subcontractors, vendor consignment held, with valuation',
        description: 'Requires permission delivery_notes.view.',
        tags: ['WMS Consignment'],
        parameters: [
            new OA\Parameter(name: 'partner_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Success')]
    )]

    #[OA\Get(
        path: '/api/v1/logistics/reports/third-party-register',
        summary: 'Third-party goods register: goods at customers, at subcontractors, vendor consignment held, with valuation',
        description: 'Requires permission delivery_notes.view.',
        tags: ['WMS Consignment'],
        parameters: [
            new OA\Parameter(name: 'partner_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Success')]
    )]
    public function register(Request $request, ThirdPartyStockRegisterService $register): JsonResponse
    {
        $data = $request->validate([
            'partner_id' => ['sometimes', 'uuid'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        return response()->json(['data' => $register->register($data['partner_id'] ?? null, $data['from'] ?? null, $data['to'] ?? null)]);
    }

    #[OA\Get(
        path: '/api/v1/wms/consignment/declarations',
        summary: 'List consumption declarations',
        description: 'Requires permission delivery_notes.view.',
        tags: ['WMS Consignment'],
        parameters: [new OA\Parameter(name: 'warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Success')]
    )]

    #[OA\Get(
        path: '/api/v1/logistics/consignment/declarations',
        summary: 'List consumption declarations',
        description: 'Requires permission delivery_notes.view.',
        tags: ['WMS Consignment'],
        parameters: [new OA\Parameter(name: 'warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Success')]
    )]

    #[OA\Get(
        path: '/api/v1/wms/consignment/consumption-reports',
        summary: 'List consumption declarations',
        description: 'Requires permission delivery_notes.view.',
        tags: ['WMS Consignment'],
        parameters: [new OA\Parameter(name: 'warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Success')]
    )]

    #[OA\Get(
        path: '/api/v1/logistics/consignment/consumption-reports',
        summary: 'List consumption declarations',
        description: 'Requires permission delivery_notes.view.',
        tags: ['WMS Consignment'],
        parameters: [new OA\Parameter(name: 'warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Success')]
    )]
    public function declarations(Request $request): JsonResponse
    {
        $query = ConsignmentConsumptionDeclaration::query()->with('lines')
            ->when($request->query('warehouse_id'), fn ($q, $v) => $q->where('warehouse_id', $v))
            ->orderByDesc('created_at');

        return response()->json($query->paginate(min(100, (int) $request->query('per_page', 25))));
    }

    #[OA\Post(
        path: '/api/v1/wms/consignment/declarations',
        summary: 'Declare periodic consumption: shifts ownership and drafts the sales invoice (customer) or purchase order (vendor)',
        description: 'Requires permission logistics.consignment.reconcile.',
        tags: ['WMS Consignment'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ConsumptionInput')),
        responses: [
            new OA\Response(response: 201, description: 'Created'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]

    #[OA\Post(
        path: '/api/v1/logistics/consignment/declarations',
        summary: 'Declare periodic consumption: shifts ownership and drafts the sales invoice (customer) or purchase order (vendor)',
        description: 'Requires permission logistics.consignment.reconcile.',
        tags: ['WMS Consignment'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ConsumptionInput')),
        responses: [
            new OA\Response(response: 201, description: 'Created'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]

    #[OA\Post(
        path: '/api/v1/wms/consignment/consumption-reports',
        summary: 'Declare periodic consumption: shifts ownership and drafts the sales invoice (customer) or purchase order (vendor)',
        description: 'Requires permission logistics.consignment.reconcile.',
        tags: ['WMS Consignment'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ConsumptionInput')),
        responses: [
            new OA\Response(response: 201, description: 'Created'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]

    #[OA\Post(
        path: '/api/v1/logistics/consignment/consumption-reports',
        summary: 'Declare periodic consumption: shifts ownership and drafts the sales invoice (customer) or purchase order (vendor)',
        description: 'Requires permission logistics.consignment.reconcile.',
        tags: ['WMS Consignment'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ConsumptionInput')),
        responses: [
            new OA\Response(response: 201, description: 'Created'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function declare(DeclareConsumptionRequest $request, DeclareConsignmentConsumptionAction $action): JsonResponse
    {
        return $this->run(fn () => response()->json(['data' => $action->execute($request->validated())], 201));
    }

    #[OA\Post(
        path: '/api/v1/wms/consignment/vendor-receipts',
        summary: 'Receive vendor-owned consignment goods on a vendor consignment warehouse',
        description: 'Requires permission logistics.consignment.reconcile. The goods stay legally owned by the vendor.',
        tags: ['WMS Consignment'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'lines', type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'quantity', type: 'number'),
            ], type: 'object')),
        ], type: 'object')),
        responses: [
            new OA\Response(response: 201, description: 'Created'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]

    #[OA\Post(
        path: '/api/v1/logistics/consignment/vendor-receipts',
        summary: 'Receive vendor-owned consignment goods on a vendor consignment warehouse',
        description: 'Requires permission logistics.consignment.reconcile. The goods stay legally owned by the vendor.',
        tags: ['WMS Consignment'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'lines', type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'quantity', type: 'number'),
            ], type: 'object')),
        ], type: 'object')),
        responses: [
            new OA\Response(response: 201, description: 'Created'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function vendorReceipt(VendorReceiptRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->run(fn () => response()->json(['data' => $this->service->receiveVendorStock($data['warehouse_id'], $data['lines'])], 201));
    }

    #[OA\Get(
        path: '/api/v1/wms/consignment/agreements',
        summary: 'List consignment agreements',
        description: 'Requires permission delivery_notes.view.',
        tags: ['WMS Consignment'],
        parameters: [
            new OA\Parameter(name: 'direction', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['customer', 'vendor'])),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Success')]
    )]

    #[OA\Get(
        path: '/api/v1/logistics/consignment/agreements',
        summary: 'List consignment agreements',
        description: 'Requires permission delivery_notes.view.',
        tags: ['WMS Consignment'],
        parameters: [
            new OA\Parameter(name: 'direction', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['customer', 'vendor'])),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Success')]
    )]
    public function agreements(Request $request): JsonResponse
    {
        $query = ConsignmentAgreement::query()
            ->when($request->query('direction'), fn ($q, $v) => $q->where('direction', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('valid_from');

        return response()->json($query->paginate(min(100, (int) $request->query('per_page', 25))));
    }

    #[OA\Post(
        path: '/api/v1/wms/consignment/agreements',
        summary: 'Create a consignment agreement for a customer or vendor consignment warehouse',
        description: 'Requires permission logistics.consignment.reconcile.',
        tags: ['WMS Consignment'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'valid_from', type: 'string', format: 'date'),
            new OA\Property(property: 'billing_frequency', type: 'string', enum: ['monthly', 'quarterly', 'on_demand']),
        ], type: 'object')),
        responses: [
            new OA\Response(response: 201, description: 'Created'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]

    #[OA\Post(
        path: '/api/v1/logistics/consignment/agreements',
        summary: 'Create a consignment agreement for a customer or vendor consignment warehouse',
        description: 'Requires permission logistics.consignment.reconcile.',
        tags: ['WMS Consignment'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'valid_from', type: 'string', format: 'date'),
            new OA\Property(property: 'billing_frequency', type: 'string', enum: ['monthly', 'quarterly', 'on_demand']),
        ], type: 'object')),
        responses: [
            new OA\Response(response: 201, description: 'Created'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function storeAgreement(StoreConsignmentAgreementRequest $request): JsonResponse
    {
        return $this->run(fn () => response()->json(['data' => $this->service->createAgreement($request->validated())], 201));
    }

    #[OA\Post(
        path: '/api/v1/wms/consignment/agreements/{id}/terminate',
        summary: 'Terminate a consignment agreement',
        description: 'Requires permission logistics.consignment.reconcile.',
        tags: ['WMS Consignment'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Success')]
    )]

    #[OA\Post(
        path: '/api/v1/logistics/consignment/agreements/{id}/terminate',
        summary: 'Terminate a consignment agreement',
        description: 'Requires permission logistics.consignment.reconcile.',
        tags: ['WMS Consignment'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Success')]
    )]
    public function terminateAgreement(string $id): JsonResponse
    {
        return response()->json(['data' => $this->service->terminateAgreement(ConsignmentAgreement::query()->findOrFail($id))]);
    }

    /**
     * @param  callable(): JsonResponse  $callback
     */
    private function run(callable $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (InvalidArgumentException|DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
