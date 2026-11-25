<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\VanSalesAndFieldForce\Application\Commands\UnblockCustomerCreditByCollectionCommand;
use Modules\VanSalesAndFieldForce\Application\Commands\UnblockCustomerCreditByCollectionHandler;
use Modules\VanSalesAndFieldForce\Application\Queries\GetCustomerFastReorderAndCreditHandler;
use Modules\VanSalesAndFieldForce\Application\Queries\GetCustomerFastReorderAndCreditQuery;
use Modules\VanSalesAndFieldForce\Http\Controllers\Concerns\InteractsWithVanSelling;
use Modules\VanSalesAndFieldForce\Http\Requests\FieldRoutePlanStoreRequest;
use Modules\VanSalesAndFieldForce\Http\Requests\Gs1ParseRequest;
use Modules\VanSalesAndFieldForce\Http\Requests\UnblockCustomerCreditRequest;
use Modules\VanSalesAndFieldForce\Http\Resources\FieldRoutePlanResource;
use Modules\VanSalesAndFieldForce\Models\FieldRoutePlan;
use Modules\VanSalesAndFieldForce\Models\VanWarehouse;
use Modules\VanSalesAndFieldForce\Services\Gs1VariableWeightBarcodeService;
use OpenApi\Attributes as OA;

final class FieldRouteAndCatalogController extends Controller
{
    use InteractsWithVanSelling;

    public function __construct(
        private readonly GetCustomerFastReorderAndCreditHandler $dossierHandler,
        private readonly UnblockCustomerCreditByCollectionHandler $unblockHandler,
        private readonly Gs1VariableWeightBarcodeService $gs1Parser,
    ) {}

    #[OA\Get(
        path: '/api/v1/van-selling/route-plans',
        operationId: 'listFieldRoutePlans',
        summary: 'List daily and recurring field route plans',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Van Selling'],
        responses: [new OA\Response(response: 200, description: 'Field route plans')]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', FieldRoutePlan::class);
        $vanId = $request->query('van_warehouse_id');

        return FieldRoutePlanResource::collection(
            FieldRoutePlan::query()
                ->with('vanWarehouse')
                ->where('tenant_id', $this->tenantId())
                ->when(is_string($vanId) && $vanId !== '', static fn ($q) => $q->where('van_warehouse_id', $vanId))
                ->orderByDesc('plan_date')
                ->paginate($this->perPage())
        );
    }

    #[OA\Post(
        path: '/api/v1/van-selling/route-plans',
        operationId: 'storeFieldRoutePlan',
        summary: 'Create a field route plan with ordered customer stops',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Van Selling'],
        responses: [new OA\Response(response: 201, description: 'Route plan created')]
    )]
    public function store(FieldRoutePlanStoreRequest $request): JsonResponse
    {
        $this->authorize('create', FieldRoutePlan::class);
        $validated = $request->validated();
        $planDate = (string) $validated['plan_date'];

        $plan = FieldRoutePlan::create([
            'tenant_id' => $this->tenantId(),
            'van_warehouse_id' => $validated['van_warehouse_id'],
            'van_load_manifest_id' => $validated['van_load_manifest_id'] ?? null,
            'route_code' => strtoupper((string) $validated['route_code']),
            'name' => $validated['name'],
            'plan_date' => $planDate,
            'weekday_iso' => (int) now()->parse($planDate)->isoWeekday(),
            'is_recurring' => (bool) ($validated['is_recurring'] ?? true),
            'salesperson_name' => $validated['salesperson_name'] ?? null,
            'territory_zone' => $validated['territory_zone'] ?? null,
            'country' => strtoupper((string) ($validated['country'] ?? 'IT')),
            'status' => 'active',
            'stops' => $validated['stops'],
            'total_stops' => count((array) $validated['stops']),
            'completed_stops' => 0,
            'notes' => $validated['notes'] ?? null,
        ]);

        return (new FieldRoutePlanResource($plan->load('vanWarehouse')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/van-selling/route-plans/{id}',
        operationId: 'showFieldRoutePlan',
        summary: 'Show a field route plan',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Van Selling'],
        responses: [new OA\Response(response: 200, description: 'Route plan')]
    )]
    public function show(string $id): FieldRoutePlanResource
    {
        $plan = FieldRoutePlan::query()->with('vanWarehouse')->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('view', $plan);

        return new FieldRoutePlanResource($plan);
    }

    #[OA\Get(
        path: '/api/v1/van-selling/customers/{contactId}/field-dossier',
        operationId: 'getCustomerFieldDossier',
        summary: 'Get customer credit limit/overdue exposure and fast reorder from last 5 purchases',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Van Selling'],
        responses: [new OA\Response(response: 200, description: 'Customer field dossier')]
    )]
    public function customerDossier(Request $request, string $contactId): JsonResponse
    {
        $this->authorize('viewAny', VanWarehouse::class);
        $proposedAmount = (float) $request->query('proposed_amount', 0);

        return response()->json([
            'data' => $this->dossierHandler->handle(
                new GetCustomerFastReorderAndCreditQuery($this->tenantId(), $contactId, $proposedAmount)
            ),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/van-selling/customers/{contactId}/unblock-credit',
        operationId: 'unblockCustomerCreditInField',
        summary: 'Collect an overdue invoice on the spot to contextually unblock customer unloading',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Van Selling'],
        responses: [new OA\Response(response: 200, description: 'Credit unblock result')]
    )]
    public function unblockCredit(UnblockCustomerCreditRequest $request, string $contactId): JsonResponse
    {
        $this->authorize('create', VanWarehouse::class);
        $validated = $request->validated();

        $result = $this->unblockHandler->handle(new UnblockCustomerCreditByCollectionCommand(
            tenantId: $this->tenantId(),
            contactId: $contactId,
            invoiceId: (string) $validated['invoice_id'],
            amount: (float) $validated['amount'],
            paymentMethod: (string) $validated['payment_method'],
            reference: $validated['reference'] ?? null,
            notes: $validated['notes'] ?? null,
        ));

        return response()->json(['data' => $result]);
    }

    #[OA\Post(
        path: '/api/v1/van-selling/gs1-parse',
        operationId: 'parseGs1VariableWeightBarcode',
        summary: 'Parse GS1-128 or EAN-13 variable-weight barcode into weight, lot, expiry and catalog product',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Van Selling'],
        responses: [new OA\Response(response: 200, description: 'Parsed GS1-128 result')]
    )]
    public function parseGs1(Gs1ParseRequest $request): JsonResponse
    {
        $this->authorize('viewAny', VanWarehouse::class);
        $result = $this->gs1Parser->parse($this->tenantId(), (string) $request->validated()['barcode']);

        return response()->json(['data' => $result->toArray()]);
    }
}
