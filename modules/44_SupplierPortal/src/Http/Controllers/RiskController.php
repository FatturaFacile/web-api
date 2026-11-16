<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Modules\SupplierPortal\Enums\RiskLevel;
use Modules\SupplierPortal\Http\Controllers\Concerns\InteractsWithSupplierPortal;
use Modules\SupplierPortal\Http\Requests\SupplierCheckRequest;
use Modules\SupplierPortal\Http\Resources\RiskProfileResource;
use Modules\SupplierPortal\Http\Resources\SupplierCheckResource;
use Modules\SupplierPortal\Models\SupplierCheck;
use Modules\SupplierPortal\Models\SupplierRiskProfile;
use Modules\SupplierPortal\Services\SupplierPortalLedger;
use Modules\SupplierPortal\Services\SupplierRiskService;
use OpenApi\Attributes as OA;

/**
 * Supplier checks (VAT, anti-mafia, sanctions, registers) and the risk flag they feed together with the documents.
 */
final class RiskController extends Controller
{
    use InteractsWithSupplierPortal;

    public function __construct(private readonly SupplierRiskService $risk, private readonly SupplierPortalLedger $ledger) {}

    #[OA\Get(path: '/api/v1/supplier-portal/risk', operationId: 'listSupplierRiskProfiles', description: 'Risk flags of the suppliers, the riskiest first. Filters: level, min_level (the level and above).', summary: 'List supplier risk flags', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Supplier Portal'], parameters: [
        new OA\Parameter(name: 'level', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['none', 'low', 'medium', 'high', 'critical'])),
        new OA\Parameter(name: 'min_level', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['low', 'medium', 'high', 'critical'])),
        new OA\Parameter(ref: '#/components/parameters/SupplierPortalPerPage'),
    ], responses: [
        new OA\Response(response: 200, description: 'Risk flags', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SpRiskProfile'))])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SupplierRiskProfile::class);
        $request->validate(['level' => ['nullable', Rule::in(RiskLevel::values())], 'min_level' => ['nullable', Rule::in(RiskLevel::values())]]);

        $minimum = $request->filled('min_level') ? RiskLevel::from((string) $request->query('min_level'))->rank() : null;
        $levels = $minimum === null ? null : array_map(static fn (RiskLevel $level): string => $level->value, array_values(array_filter(RiskLevel::cases(), static fn (RiskLevel $level): bool => $level->rank() >= $minimum)));

        return RiskProfileResource::collection(
            SupplierRiskProfile::query()
                ->with('supplier')
                ->where('tenant_id', $this->tenantId())
                ->when($request->filled('level'), static fn ($query) => $query->where('level', (string) $request->query('level')))
                ->when($levels !== null, static fn ($query) => $query->whereIn('level', $levels))
                ->orderByDesc('score')
                ->gridPaginate($this->perPage())
        );
    }

    #[OA\Get(path: '/api/v1/supplier-portal/suppliers/{supplierId}/risk', operationId: 'showSupplierRisk', description: 'The risk flag of a supplier, recalculated now, with its reasons and the latest check of every type.', summary: 'Show the risk of a supplier', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Supplier Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/SupplierPortalSupplierId')], responses: [
        new OA\Response(response: 200, description: 'Risk and checks', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'risk', ref: '#/components/schemas/SpRiskProfile'), new OA\Property(property: 'checks', type: 'array', items: new OA\Items(ref: '#/components/schemas/SpSupplierCheck'))], type: 'object')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SupplierPortalNotFound', response: 404),
    ])]
    public function show(string $supplierId): JsonResponse
    {
        $this->authorize('viewAny', SupplierRiskProfile::class);
        $supplier = $this->supplier($supplierId);
        $profile = $this->risk->evaluate($supplier);

        return response()->json(['data' => [
            'risk' => (new RiskProfileResource($profile->load('supplier')))->resolve(),
            'checks' => SupplierCheckResource::collection($this->risk->latestChecks($supplierId)->values())->resolve(),
        ]]);
    }

    #[OA\Post(path: '/api/v1/supplier-portal/risk/recalculate', operationId: 'recalculateSupplierRisk', description: 'Recalculates the risk flag of every supplier that has an onboarding, a document or a check.', summary: 'Recalculate every risk flag', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Supplier Portal'], responses: [
        new OA\Response(response: 200, description: 'Suppliers evaluated', content: new OA\JsonContent(properties: [new OA\Property(property: 'evaluated', type: 'integer')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
    ])]
    public function recalculate(): JsonResponse
    {
        $this->authorize('create', SupplierRiskProfile::class);

        return response()->json(['evaluated' => $this->risk->evaluateAll()]);
    }

    #[OA\Get(path: '/api/v1/supplier-portal/checks', operationId: 'listSupplierChecks', description: 'Paginated supplier checks, newest first. Filters: supplier_id, check_type, result.', summary: 'List supplier checks', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Supplier Portal'], parameters: [
        new OA\Parameter(name: 'supplier_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'check_type', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'result', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'passed', 'failed'])),
        new OA\Parameter(ref: '#/components/parameters/SupplierPortalPerPage'),
    ], responses: [
        new OA\Response(response: 200, description: 'Checks', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SpSupplierCheck'))])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
    ])]
    public function checks(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SupplierCheck::class);

        return SupplierCheckResource::collection(
            SupplierCheck::query()
                ->where('tenant_id', $this->tenantId())
                ->when($request->filled('supplier_id'), static fn ($query) => $query->where('supplier_id', (string) $request->query('supplier_id')))
                ->when($request->filled('check_type'), static fn ($query) => $query->where('check_type', (string) $request->query('check_type')))
                ->when($request->filled('result'), static fn ($query) => $query->where('result', (string) $request->query('result')))
                ->orderByDesc('created_at')
                ->gridPaginate($this->perPage())
        );
    }

    #[OA\Post(path: '/api/v1/supplier-portal/checks', operationId: 'storeSupplierCheck', description: 'Records the outcome of a verification (VIES VAT, VAT status, anti-mafia, sanctions, business register, online DURC). The risk flag of the supplier is recalculated: a failed VAT status is a blocked VAT position, any other failed check raises the risk and stops the approval of the supplier.', summary: 'Record a supplier check', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SpSupplierCheckRequest')), tags: ['Supplier Portal'], responses: [
        new OA\Response(response: 201, description: 'Check recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SpSupplierCheck')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnprocessable', response: 422),
    ])]
    public function storeCheck(SupplierCheckRequest $request): JsonResponse
    {
        $this->authorize('create', SupplierCheck::class);
        $supplier = $this->supplier((string) $request->validated('supplier_id'));

        $check = SupplierCheck::create([
            ...$request->validated(),
            'tenant_id' => $this->tenantId(),
            'checked_at' => $request->validated('checked_at') ?? ($request->validated('result') === 'pending' ? null : now()->toDateString()),
            'checked_by' => $this->userId(),
        ]);

        $this->ledger->record('SUPPLIER_CHECK_RECORDED', $check, ['supplier_id' => $supplier->id, 'check_type' => $check->check_type, 'result' => $check->result]);
        $this->risk->evaluate($supplier);

        return (new SupplierCheckResource($check->refresh()))->response()->setStatusCode(201);
    }
}
