<?php

declare(strict_types=1);

namespace Modules\Rebates\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Modules\Rebates\Http\Controllers\Concerns\InteractsWithRebates;
use Modules\Rebates\Http\Requests\AgreementRequest;
use Modules\Rebates\Http\Requests\CalculateRequest;
use Modules\Rebates\Http\Requests\SettleRequest;
use Modules\Rebates\Http\Resources\AccrualResource;
use Modules\Rebates\Http\Resources\AgreementResource;
use Modules\Rebates\Http\Resources\SettlementResource;
use Modules\Rebates\Models\RebateAccrual;
use Modules\Rebates\Models\RebateAgreement;
use Modules\Rebates\Services\RebateAccrualService;
use Modules\Rebates\Services\RebateAgreementService;
use OpenApi\Attributes as OA;

/**
 * Volume rebate agreements with customers and suppliers: tiers, accrual from invoiced volume and settlement.
 */
final class AgreementController extends Controller
{
    use InteractsWithRebates;

    public function __construct(private readonly RebateAgreementService $agreements, private readonly RebateAccrualService $accruals) {}

    #[OA\Get(path: '/api/v1/rebates/agreements', operationId: 'listRebateAgreements', description: 'Rebate agreements with their tiers and the rebate accrued and not settled yet. Filters: direction (sales, purchase), status, contact_id, search (code or name).', summary: 'List rebate agreements', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Rebates'], responses: [
        new OA\Response(response: 200, description: 'Rebate agreements', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/RebateAgreement'))])),
        new OA\Response(ref: '#/components/responses/RebatesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/RebatesForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', RebateAgreement::class);
        $filters = $request->validate([
            'direction' => ['nullable', 'in:'.implode(',', RebateAgreement::Directions)],
            'status' => ['nullable', 'in:'.implode(',', RebateAgreement::Statuses)],
            'contact_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return AgreementResource::collection(
            RebateAgreement::query()
                ->with(['tiers', 'contact'])
                ->withSum(['accruals as open_rebate_sum' => static fn ($query) => $query->where('status', RebateAccrual::Accrued)], 'rebate_amount')
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['direction']), static fn ($query) => $query->where('direction', $filters['direction']))
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->when(isset($filters['contact_id']), static fn ($query) => $query->where('contact_id', $filters['contact_id']))
                ->when(isset($filters['search']), static fn ($query) => $query->where(static fn ($inner) => $inner->where('code', 'like', '%'.$filters['search'].'%')->orWhere('name', 'like', '%'.$filters['search'].'%')))
                ->orderByDesc('starts_on')
                ->orderBy('code')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/rebates/agreements', operationId: 'storeRebateAgreement', description: 'Create a draft agreement with its scale of tiers. Retroactive: the rate of the highest tier reached applies to the whole volume; incremental: every band of volume earns the rate of its own tier. The code is unique in the company.', summary: 'Create a rebate agreement', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RebateAgreementRequest')), tags: ['Rebates'], responses: [
        new OA\Response(response: 201, description: 'Agreement created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RebateAgreement')])),
        new OA\Response(ref: '#/components/responses/RebatesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/RebatesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/RebatesUnprocessable', response: 422),
    ])]
    public function store(AgreementRequest $request): JsonResponse
    {
        $this->authorize('create', RebateAgreement::class);

        return (new AgreementResource($this->agreements->create($this->tenantId(), $request->validated())->load('contact')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/rebates/agreements/{id}', operationId: 'showRebateAgreement', description: 'Return an agreement with its tiers.', summary: 'Show a rebate agreement', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Rebates'], parameters: [new OA\Parameter(ref: '#/components/parameters/RebatesId')], responses: [
        new OA\Response(response: 200, description: 'Rebate agreement', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RebateAgreement')])),
        new OA\Response(ref: '#/components/responses/RebatesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/RebatesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/RebatesNotFound', response: 404),
    ])]
    public function show(string $id): AgreementResource
    {
        $agreement = RebateAgreement::query()->with(['tiers', 'contact', 'contributions'])->withSum(['accruals as open_rebate_sum' => static fn ($query) => $query->where('status', RebateAccrual::Accrued)], 'rebate_amount')->findOrFail($id);
        $this->authorize('view', $agreement);

        return new AgreementResource($agreement);
    }

    #[OA\Put(path: '/api/v1/rebates/agreements/{id}', operationId: 'updateRebateAgreement', description: 'Change an agreement. A draft is freely editable (the tiers are replaced as a whole when sent); once active only the name, the notes and the end date can change.', summary: 'Update a rebate agreement', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RebateAgreementRequest')), tags: ['Rebates'], parameters: [new OA\Parameter(ref: '#/components/parameters/RebatesId')], responses: [
        new OA\Response(response: 200, description: 'Agreement updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RebateAgreement')])),
        new OA\Response(ref: '#/components/responses/RebatesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/RebatesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/RebatesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/RebatesUnprocessable', response: 422),
    ])]
    public function update(AgreementRequest $request, string $id): AgreementResource
    {
        $agreement = RebateAgreement::query()->findOrFail($id);
        $this->authorize('update', $agreement);

        return new AgreementResource($this->agreements->update($agreement, $request->validated())->load('contact'));
    }

    #[OA\Delete(path: '/api/v1/rebates/agreements/{id}', operationId: 'deleteRebateAgreement', description: 'Delete a draft agreement without accruals (soft delete).', summary: 'Delete a rebate agreement', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Rebates'], parameters: [new OA\Parameter(ref: '#/components/parameters/RebatesId')], responses: [
        new OA\Response(response: 204, description: 'Agreement deleted'),
        new OA\Response(ref: '#/components/responses/RebatesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/RebatesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/RebatesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/RebatesUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $agreement = RebateAgreement::query()->findOrFail($id);
        $this->authorize('delete', $agreement);
        $this->agreements->delete($agreement);

        return response()->noContent();
    }

    #[OA\Post(path: '/api/v1/rebates/agreements/{id}/activate', operationId: 'activateRebateAgreement', description: 'Activate a draft agreement that has at least one tier: the structure is locked and accruals can be calculated.', summary: 'Activate a rebate agreement', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Rebates'], parameters: [new OA\Parameter(ref: '#/components/parameters/RebatesId')], responses: [
        new OA\Response(response: 200, description: 'Agreement activated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RebateAgreement')])),
        new OA\Response(ref: '#/components/responses/RebatesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/RebatesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/RebatesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/RebatesUnprocessable', response: 422),
    ])]
    public function activate(string $id): AgreementResource
    {
        $agreement = RebateAgreement::query()->findOrFail($id);
        $this->authorize('update', $agreement);

        return new AgreementResource($this->agreements->activate($agreement));
    }

    #[OA\Post(path: '/api/v1/rebates/agreements/{id}/close', operationId: 'closeRebateAgreement', description: 'Close an active agreement: no further accruals are calculated; open accruals can still be settled.', summary: 'Close a rebate agreement', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Rebates'], parameters: [new OA\Parameter(ref: '#/components/parameters/RebatesId')], responses: [
        new OA\Response(response: 200, description: 'Agreement closed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RebateAgreement')])),
        new OA\Response(ref: '#/components/responses/RebatesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/RebatesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/RebatesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/RebatesUnprocessable', response: 422),
    ])]
    public function close(string $id): AgreementResource
    {
        $agreement = RebateAgreement::query()->findOrFail($id);
        $this->authorize('update', $agreement);

        return new AgreementResource($this->agreements->close($agreement));
    }

    #[OA\Post(path: '/api/v1/rebates/agreements/{id}/calculate', operationId: 'calculateRebateAccrual', description: 'Calculate the rebate on the net volume invoiced from the start of the agreement up to as_of (default today): issued invoices minus credit notes for customers, received invoices minus credit notes for suppliers. Only the part not accrued yet becomes a new accrual, so repeating the call is harmless; the accrual is null when nothing changed.', summary: 'Calculate the rebate accrual', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/RebateCalculateRequest')), tags: ['Rebates'], parameters: [new OA\Parameter(ref: '#/components/parameters/RebatesId')], responses: [
        new OA\Response(response: 200, description: 'Calculation result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RebateCalculation')])),
        new OA\Response(ref: '#/components/responses/RebatesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/RebatesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/RebatesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/RebatesUnprocessable', response: 422),
    ])]
    public function calculate(CalculateRequest $request, string $id): JsonResponse
    {
        $agreement = RebateAgreement::query()->findOrFail($id);
        $this->authorize('update', $agreement);
        $result = $this->accruals->calculate($agreement, Carbon::parse((string) ($request->validated('as_of') ?? now()->toDateString()))->startOfDay());

        return response()->json(['data' => [...$result, 'accrual' => $result['accrual'] === null ? null : (new AccrualResource($result['accrual']))->resolve()]]);
    }

    #[OA\Get(path: '/api/v1/rebates/agreements/{id}/accruals', operationId: 'listRebateAccruals', description: 'Accrual runs of an agreement, oldest first.', summary: 'List the accruals of an agreement', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Rebates'], parameters: [new OA\Parameter(ref: '#/components/parameters/RebatesId')], responses: [
        new OA\Response(response: 200, description: 'Accruals', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/RebateAccrual'))])),
        new OA\Response(ref: '#/components/responses/RebatesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/RebatesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/RebatesNotFound', response: 404),
    ])]
    public function accruals(string $id): AnonymousResourceCollection
    {
        $agreement = RebateAgreement::query()->findOrFail($id);
        $this->authorize('view', $agreement);

        return AccrualResource::collection($agreement->accruals()->get());
    }

    #[OA\Post(path: '/api/v1/rebates/agreements/{id}/settle', operationId: 'settleRebateAgreement', description: 'Settle the open accruals. A customer agreement creates a credit note draft in invoicing with one line for the rebate (tax_rate is required); a supplier agreement records a claim to the supplier. The accruals become settled.', summary: 'Settle the accrued rebate', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/RebateSettleRequest')), tags: ['Rebates'], parameters: [new OA\Parameter(ref: '#/components/parameters/RebatesId')], responses: [
        new OA\Response(response: 201, description: 'Settlement created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RebateSettlement')])),
        new OA\Response(ref: '#/components/responses/RebatesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/RebatesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/RebatesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/RebatesUnprocessable', response: 422),
    ])]
    public function settle(SettleRequest $request, string $id): JsonResponse
    {
        $agreement = RebateAgreement::query()->findOrFail($id);
        $this->authorize('update', $agreement);

        return (new SettlementResource($this->accruals->settle($agreement, $request->validated(), $this->userId())->load('agreement')))->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
