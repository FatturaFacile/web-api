<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Budgeting\Http\Controllers\Concerns\InteractsWithBudgeting;
use Modules\Budgeting\Http\Requests\ReforecastRequest;
use Modules\Budgeting\Http\Requests\VersionCopyRequest;
use Modules\Budgeting\Http\Requests\VersionRequest;
use Modules\Budgeting\Http\Resources\VersionResource;
use Modules\Budgeting\Models\BudgetVersion;
use Modules\Budgeting\Services\ReforecastService;
use Modules\Budgeting\Services\VersionService;
use OpenApi\Attributes as OA;

/**
 * Budget versions of a fiscal year: budgets, forecasts and rolling forecasts.
 */
final class VersionController extends Controller
{
    use InteractsWithBudgeting;

    public function __construct(private readonly VersionService $versions, private readonly ReforecastService $reforecasts) {}

    #[OA\Get(path: '/api/v1/budgeting/versions', operationId: 'listBudgetVersions', description: 'Budget versions, the latest fiscal year first. Filters: fiscal_year, status, version_type.', summary: 'List budget versions', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], responses: [
        new OA\Response(response: 200, description: 'Versions', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/BudgetVersion'))])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', BudgetVersion::class);
        $filters = $request->validate([
            'fiscal_year' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(BudgetVersion::Statuses)],
            'version_type' => ['nullable', Rule::in(BudgetVersion::Types)],
        ]);

        return VersionResource::collection(
            BudgetVersion::query()
                ->withCount(['lines', 'drivers'])
                ->withSum('lines', 'amount')
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['fiscal_year']), static fn ($query) => $query->where('fiscal_year', $filters['fiscal_year']))
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->when(isset($filters['version_type']), static fn ($query) => $query->where('version_type', $filters['version_type']))
                ->orderByDesc('fiscal_year')
                ->orderByDesc('created_at')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/budgeting/versions', operationId: 'storeBudgetVersion', description: 'Create a draft version. The name is unique within the fiscal year.', summary: 'Create a budget version', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BudgetVersionRequest')), tags: ['Budgeting'], responses: [
        new OA\Response(response: 201, description: 'Version created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BudgetVersion')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function store(VersionRequest $request): JsonResponse
    {
        $this->authorize('create', BudgetVersion::class);

        return (new VersionResource($this->versions->create($this->tenantId(), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/budgeting/versions/{id}', operationId: 'showBudgetVersion', description: 'Return a version with its approval trail.', summary: 'Show a budget version', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], responses: [
        new OA\Response(response: 200, description: 'Version', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BudgetVersion')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
    ])]
    public function show(string $id): VersionResource
    {
        $version = BudgetVersion::query()->withCount(['lines', 'drivers'])->withSum('lines', 'amount')->with('approvals')->findOrFail($id);
        $this->authorize('view', $version);

        return new VersionResource($version);
    }

    #[OA\Put(path: '/api/v1/budgeting/versions/{id}', operationId: 'updateBudgetVersion', description: 'Change a draft version. A version that was submitted, approved or locked is not edited.', summary: 'Update a budget version', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BudgetVersionRequest')), tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], responses: [
        new OA\Response(response: 200, description: 'Version updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BudgetVersion')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function update(VersionRequest $request, string $id): VersionResource
    {
        $version = BudgetVersion::query()->findOrFail($id);
        $this->authorize('update', $version);

        return new VersionResource($this->versions->update($version, $request->validated()));
    }

    #[OA\Delete(path: '/api/v1/budgeting/versions/{id}', operationId: 'deleteBudgetVersion', description: 'Delete a draft version with its lines and drivers (soft delete).', summary: 'Delete a budget version', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], responses: [
        new OA\Response(response: 204, description: 'Version deleted'),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $version = BudgetVersion::query()->findOrFail($id);
        $this->authorize('delete', $version);
        $this->versions->delete($version);

        return response()->noContent();
    }

    #[OA\Post(path: '/api/v1/budgeting/versions/{id}/copy', operationId: 'copyBudgetVersion', description: 'Create a draft copy of a version of any status with its lines and drivers, as a new budget or as a forecast (default).', summary: 'Copy a budget version', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BudgetVersionCopyRequest')), tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], responses: [
        new OA\Response(response: 201, description: 'Copy created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BudgetVersion')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function copy(VersionCopyRequest $request, string $id): JsonResponse
    {
        $source = BudgetVersion::query()->findOrFail($id);
        $this->authorize('view', $source);
        $this->authorize('create', BudgetVersion::class);

        return (new VersionResource($this->versions->copy($source, $request->validated())->loadCount(['lines', 'drivers'])->loadSum('lines', 'amount')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Post(path: '/api/v1/budgeting/versions/{id}/reforecast', operationId: 'reforecastBudgetVersion', description: 'Create a draft rolling forecast of an approved or locked version: the periods before from_period hold the actuals of the ledger, the others are re-forecast. Mode keep_annual_target (default) spreads what is left of the annual amount over the open periods in the proportion of the base version, mode keep_remaining keeps the open periods of the base. adjustment_percent scales the open periods.', summary: 'Re-forecast the remaining periods', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BudgetReforecastRequest')), tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], responses: [
        new OA\Response(response: 201, description: 'Rolling forecast created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BudgetVersion')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function reforecast(ReforecastRequest $request, string $id): JsonResponse
    {
        $base = BudgetVersion::query()->findOrFail($id);
        $this->authorize('view', $base);
        $this->authorize('create', BudgetVersion::class);

        return (new VersionResource($this->reforecasts->reforecast($base, $request->validated())->loadCount(['lines', 'drivers'])->loadSum('lines', 'amount')))->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
