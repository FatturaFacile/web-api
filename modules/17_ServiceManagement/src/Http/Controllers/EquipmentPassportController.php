<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ServiceManagement\Contracts\EquipmentPassportServiceInterface;
use Modules\ServiceManagement\DTOs\AddWarrantyExtensionDTO;
use Modules\ServiceManagement\DTOs\RegisterEquipmentPassportDTO;
use Modules\ServiceManagement\DTOs\UpdateEquipmentPassportDTO;
use Modules\ServiceManagement\Exceptions\WarrantyRmaException;
use Modules\ServiceManagement\Http\Requests\StoreEquipmentPassportRequest;
use Modules\ServiceManagement\Http\Requests\StoreWarrantyExtensionRequest;
use Modules\ServiceManagement\Http\Requests\UpdateEquipmentPassportRequest;
use Modules\ServiceManagement\Http\Resources\EquipmentComponentResource;
use Modules\ServiceManagement\Http\Resources\EquipmentPassportResource;
use Modules\ServiceManagement\Models\SerializedEquipmentPassport;
use OpenApi\Attributes as OA;

final class EquipmentPassportController extends Controller
{
    public function __construct(
        private readonly EquipmentPassportServiceInterface $passportService,
        private readonly TenantContext $tenantContext,
    ) {}

    #[OA\Get(
        path: '/api/v1/esm/equipment-passports',
        operationId: 'listEsmEquipmentPassports',
        description: 'Paginated list of serialized equipment passports with warranty status, filterable by serial prefix, owner, jurisdiction, product, asset status and warranty window.',
        summary: 'List equipment passports',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Equipment Passports'],
        parameters: [new OA\Parameter(name: 'serial_number', in: 'query', required: false, description: 'Serial number prefix', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'customer_id', in: 'query', required: false, description: 'Current owner customer', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'jurisdiction', in: 'query', required: false, description: 'Jurisdiction (CH or IT)', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'product_id', in: 'query', required: false, description: 'Product', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'asset_status', in: 'query', required: false, description: 'Asset status: active, under_repair, decommissioned', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'expiring_within_days', in: 'query', required: false, description: 'Warranty expiring within N days', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'warranty', in: 'query', required: false, description: 'Warranty filter: in or out', schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Paginated passports', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'per_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SerializedEquipmentPassport::class);

        $today = now()->toDateString();

        $passports = SerializedEquipmentPassport::query()
            ->with(['customer', 'components', 'extensions'])
            ->when($request->filled('serial_number'), fn ($query) => $query->where('serial_number', 'like', $request->string('serial_number')->trim()->value().'%'))
            ->when($request->filled('customer_id'), fn ($query) => $query->where('current_owner_customer_id', $request->query('customer_id')))
            ->when($request->filled('jurisdiction'), fn ($query) => $query->where('jurisdiction', strtoupper((string) $request->query('jurisdiction'))))
            ->when($request->filled('product_id'), fn ($query) => $query->where('product_id', $request->query('product_id')))
            ->when($request->filled('asset_status'), fn ($query) => $query->where('asset_status', $request->query('asset_status')))
            ->when($request->filled('expiring_within_days'), fn ($query) => $query
                ->whereDate('warranty_expiry_date', '>=', $today)
                ->whereDate('warranty_expiry_date', '<=', now()->addDays((int) $request->query('expiring_within_days'))->toDateString()))
            ->when($request->query('warranty') === 'in', fn ($query) => $query->whereDate('warranty_expiry_date', '>=', $today))
            ->when($request->query('warranty') === 'out', fn ($query) => $query->whereDate('warranty_expiry_date', '<', $today))
            ->orderByDesc('created_at')
            ->paginate(25);

        return response()->json([
            'data' => EquipmentPassportResource::collection($passports->getCollection()),
            'meta' => [
                'current_page' => $passports->currentPage(),
                'last_page' => $passports->lastPage(),
                'per_page' => $passports->perPage(),
                'total' => $passports->total(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/esm/equipment-passports',
        operationId: 'createEsmEquipmentPassport',
        description: 'Registers a serialized equipment passport with its as-built components and computes the warranty expiry.',
        summary: 'Register equipment passport',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Equipment Passports'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['serial_number', 'current_owner_customer_id', 'delivery_date'], properties: [new OA\Property(property: 'serial_number', type: 'string', description: 'Serial number'), new OA\Property(property: 'stock_serial_number_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'current_owner_customer_id', type: 'string', format: 'uuid'), new OA\Property(property: 'original_sales_invoice_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'customer_type', type: 'string', enum: ['b2b', 'b2c'], nullable: true), new OA\Property(property: 'jurisdiction', type: 'string', enum: ['CH', 'IT'], nullable: true), new OA\Property(property: 'delivery_date', type: 'string', format: 'date'), new OA\Property(property: 'contract_warranty_months', type: 'integer', nullable: true), new OA\Property(property: 'contract_clause_reference', type: 'string', nullable: true), new OA\Property(property: 'installed_firmware_version', type: 'string', nullable: true), new OA\Property(property: 'asset_status', type: 'string', enum: ['active', 'under_repair', 'decommissioned'], nullable: true), new OA\Property(property: 'commissioning_date', type: 'string', format: 'date', nullable: true), new OA\Property(property: 'installation_site', type: 'string', nullable: true), new OA\Property(property: 'notes', type: 'string', nullable: true), new OA\Property(property: 'components', type: 'array', nullable: true, items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'position', type: 'string'), new OA\Property(property: 'component_serial', type: 'string'), new OA\Property(property: 'component_product_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'role', type: 'string', nullable: true)]))])),
        responses: [new OA\Response(response: 201, description: 'Passport registered', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
    )]
    public function store(StoreEquipmentPassportRequest $request): JsonResponse
    {
        $this->authorize('create', SerializedEquipmentPassport::class);
        $tenant = $this->tenant();

        $passport = $this->passportService->register(
            RegisterEquipmentPassportDTO::fromValidated($tenant->id, $tenant->id, $request->validated()),
            $request->user()?->id,
        );

        return response()->json([
            'message' => 'Equipment passport registered successfully',
            'data' => new EquipmentPassportResource($passport),
        ], 201);
    }

    #[OA\Get(
        path: '/api/v1/esm/equipment-passports/lookup',
        operationId: 'lookupEsmEquipmentPassport',
        description: 'Finds a passport by serial number and returns warranty status, RMA history and stock hint.',
        summary: 'Look up passport by serial number',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Equipment Passports'],
        parameters: [new OA\Parameter(name: 'serial_number', in: 'query', required: false, description: 'Serial number to look up', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'product_id', in: 'query', required: false, description: 'Optional product to disambiguate', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Lookup result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'passport', type: 'object'), new OA\Property(property: 'warranty', type: 'object'), new OA\Property(property: 'rma_history', type: 'array', items: new OA\Items(type: 'object')), new OA\Property(property: 'stock_hint', type: 'object', nullable: true)])])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
    )]
    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SerializedEquipmentPassport::class);

        $serial = trim((string) $request->query('serial_number', ''));
        if ($serial === '') {
            throw WarrantyRmaException::validation('serial_number', 'The serial number field is required.');
        }

        $productId = $request->filled('product_id') ? (string) $request->query('product_id') : null;
        $result = $this->passportService->lookup($serial, $productId);

        return response()->json([
            'data' => [
                'passport' => new EquipmentPassportResource($result['passport']),
                'warranty' => $result['warranty'],
                'rma_history' => $result['rma_history'],
                'stock_hint' => $result['stock_hint'],
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/esm/equipment-passports/{passport}',
        operationId: 'showEsmEquipmentPassport',
        description: 'Returns one passport with customer, components and warranty extensions.',
        summary: 'Show equipment passport',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Equipment Passports'],
        parameters: [new OA\Parameter(name: 'passport', in: 'path', required: true, description: 'Identifier of the equipment passport', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Passport', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
    )]
    public function show(SerializedEquipmentPassport $passport): JsonResponse
    {
        $this->authorize('view', $passport);

        return response()->json([
            'data' => new EquipmentPassportResource($passport->load(['customer', 'components', 'extensions'])),
        ]);
    }

    #[OA\Put(
        path: '/api/v1/esm/equipment-passports/{passport}',
        operationId: 'updateEsmEquipmentPassport',
        description: 'Updates mutable passport fields; only the fields sent are changed.',
        summary: 'Update equipment passport',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Equipment Passports'],
        parameters: [new OA\Parameter(name: 'passport', in: 'path', required: true, description: 'Identifier of the equipment passport', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'current_owner_customer_id', type: 'string', format: 'uuid'), new OA\Property(property: 'original_sales_invoice_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'customer_type', type: 'string', enum: ['b2b', 'b2c'], nullable: true), new OA\Property(property: 'jurisdiction', type: 'string', enum: ['CH', 'IT'], nullable: true), new OA\Property(property: 'delivery_date', type: 'string', format: 'date'), new OA\Property(property: 'contract_warranty_months', type: 'integer', nullable: true), new OA\Property(property: 'contract_clause_reference', type: 'string', nullable: true), new OA\Property(property: 'installed_firmware_version', type: 'string', nullable: true), new OA\Property(property: 'asset_status', type: 'string', enum: ['active', 'under_repair', 'decommissioned'], nullable: true), new OA\Property(property: 'commissioning_date', type: 'string', format: 'date', nullable: true), new OA\Property(property: 'installation_site', type: 'string', nullable: true), new OA\Property(property: 'notes', type: 'string', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'Passport updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
    )]
    public function update(UpdateEquipmentPassportRequest $request, SerializedEquipmentPassport $passport): JsonResponse
    {
        $this->authorize('update', $passport);

        $updated = $this->passportService->update(
            $passport,
            UpdateEquipmentPassportDTO::fromValidated($request->validated()),
            $request->user()?->id,
        );

        return response()->json([
            'message' => 'Equipment passport updated successfully',
            'data' => new EquipmentPassportResource($updated),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/esm/equipment-passports/{passport}/extensions',
        operationId: 'createEsmEquipmentPassportExtension',
        description: 'Adds a paid or contractual warranty extension to the passport.',
        summary: 'Add warranty extension',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Equipment Passports'],
        parameters: [new OA\Parameter(name: 'passport', in: 'path', required: true, description: 'Identifier of the equipment passport', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['name', 'months', 'starts_at'], properties: [new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'months', type: 'integer'), new OA\Property(property: 'starts_at', type: 'string', format: 'date'), new OA\Property(property: 'reference', type: 'string', nullable: true), new OA\Property(property: 'price', type: 'number', nullable: true), new OA\Property(property: 'currency', type: 'string', nullable: true, description: 'ISO 4217 code')])),
        responses: [new OA\Response(response: 201, description: 'Extension added', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
    )]
    public function storeExtension(StoreWarrantyExtensionRequest $request, SerializedEquipmentPassport $passport): JsonResponse
    {
        $this->authorize('addExtension', $passport);

        $updated = $this->passportService->addExtension(
            $passport,
            AddWarrantyExtensionDTO::fromValidated($request->validated()),
            $request->user()?->id,
        );

        return response()->json([
            'message' => 'Warranty extension added successfully',
            'data' => new EquipmentPassportResource($updated),
        ], 201);
    }

    #[OA\Get(
        path: '/api/v1/esm/equipment-passports/{passport}/genealogy',
        operationId: 'getEsmEquipmentPassportGenealogy',
        description: 'Returns as-built and as-maintained component trees plus the change history.',
        summary: 'Get passport genealogy',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Equipment Passports'],
        parameters: [new OA\Parameter(name: 'passport', in: 'path', required: true, description: 'Identifier of the equipment passport', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Genealogy', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'as_built', type: 'array', items: new OA\Items(type: 'object')), new OA\Property(property: 'as_maintained', type: 'array', items: new OA\Items(type: 'object')), new OA\Property(property: 'history', type: 'array', items: new OA\Items(type: 'object'))])])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
    )]
    public function genealogy(Request $request, SerializedEquipmentPassport $passport): JsonResponse
    {
        $this->authorize('view', $passport);

        $genealogy = $this->passportService->genealogy($passport);

        return response()->json([
            'data' => [
                'as_built' => EquipmentComponentResource::collection(collect($genealogy['as_built']))->resolve($request),
                'as_maintained' => EquipmentComponentResource::collection(collect($genealogy['as_maintained']))->resolve($request),
                'history' => $genealogy['history'],
            ],
        ]);
    }

    private function tenant(): Tenant
    {
        $tenant = $this->tenantContext->getTenant();
        abort_if($tenant === null, 403, 'No active tenant.');

        return $tenant;
    }
}
