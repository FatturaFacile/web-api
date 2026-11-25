<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Budgeting\Http\Controllers\Concerns\InteractsWithBudgeting;
use Modules\Budgeting\Http\Requests\GridSaveRequest;
use Modules\Budgeting\Http\Requests\ReviseRequest;
use Modules\Budgeting\Http\Requests\RollForwardRequest;
use Modules\Budgeting\Http\Requests\ScenarioRequest;
use Modules\Budgeting\Http\Requests\TransferRequest;
use Modules\Budgeting\Http\Resources\AuditEventResource;
use Modules\Budgeting\Http\Resources\VersionResource;
use Modules\Budgeting\Models\BudgetAuditEvent;
use Modules\Budgeting\Models\BudgetVersion;
use Modules\Budgeting\Services\GridService;
use Modules\Budgeting\Services\RevisionService;
use OpenApi\Attributes as OA;

/**
 * The multidimensional grid of a version and the version family around the approved budget: revisions, scenarios,
 * transfers and the roll forward of a rolling forecast.
 */
final class GridController extends Controller
{
    use InteractsWithBudgeting;

    public function __construct(private readonly GridService $grid, private readonly RevisionService $revisions) {}

    #[OA\Get(path: '/api/v1/budgeting/versions/{id}/grid', operationId: 'showBudgetGrid', description: 'The grid of a version: account, cost centre and project rows against twelve months, or four quarters for a quarterly version, with the totals.', summary: 'Budget grid', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], responses: [
        new OA\Response(response: 200, description: 'The grid', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
    ])]
    public function show(string $id): JsonResponse
    {
        $version = BudgetVersion::query()->findOrFail($id);
        $this->authorize('view', $version);

        return response()->json(['data' => $this->grid->grid($version)]);
    }

    #[OA\Put(path: '/api/v1/budgeting/versions/{id}/grid', operationId: 'saveBudgetGrid', description: 'Save cells of the grid of a draft version. A quarterly cell is spread evenly over its three months. The change of every cell is kept in the audit trail.', summary: 'Save budget grid cells', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [
        new OA\Response(response: 200, description: 'Cells saved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function save(GridSaveRequest $request, string $id): JsonResponse
    {
        $version = BudgetVersion::query()->findOrFail($id);
        $this->authorize('update', $version);

        return response()->json(['data' => $this->grid->save($version, $request->validated()['cells'], $this->userId())]);
    }

    #[OA\Post(path: '/api/v1/budgeting/versions/{id}/revise', operationId: 'reviseBudgetVersion', description: 'Create a draft revised budget from an approved or locked original or revised budget. The approved budget itself is never changed. The reason is mandatory.', summary: 'Revise an approved budget', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [
        new OA\Response(response: 201, description: 'Revised version created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BudgetVersion')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function revise(ReviseRequest $request, string $id): JsonResponse
    {
        $baseline = BudgetVersion::query()->findOrFail($id);
        $this->authorize('view', $baseline);
        $this->authorize('create', BudgetVersion::class);

        return (new VersionResource($this->revisions->revise($baseline, $request->validated(), $this->userId())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Post(path: '/api/v1/budgeting/versions/{id}/scenario', operationId: 'createBudgetScenario', description: 'Create a draft scenario (best case, worst case or custom) from any version, with every amount scaled by an adjustment percentage.', summary: 'Create a budget scenario', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [
        new OA\Response(response: 201, description: 'Scenario created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BudgetVersion')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function scenario(ScenarioRequest $request, string $id): JsonResponse
    {
        $base = BudgetVersion::query()->findOrFail($id);
        $this->authorize('view', $base);
        $this->authorize('create', BudgetVersion::class);

        return (new VersionResource($this->revisions->scenario($base, $request->validated(), $this->userId())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Post(path: '/api/v1/budgeting/versions/{id}/transfer', operationId: 'transferBudgetAmount', description: 'Move an amount from one line of a draft version to another in the same period. The total does not change and the transfer is kept in the audit trail.', summary: 'Transfer budget between lines', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [
        new OA\Response(response: 204, description: 'Transferred'),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function transfer(TransferRequest $request, string $id): Response
    {
        $version = BudgetVersion::query()->findOrFail($id);
        $this->authorize('update', $version);
        $this->revisions->transfer($version, $request->validated(), $this->userId());

        return response()->noContent();
    }

    #[OA\Post(path: '/api/v1/budgeting/versions/{id}/roll-forward', operationId: 'rollForwardBudgetVersion', description: 'Roll an approved rolling forecast forward: a new draft whose twelve-month window starts later, with the closed months at actual and the months ahead from the forecast.', summary: 'Roll a rolling forecast forward', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')), responses: [
        new OA\Response(response: 201, description: 'Rolling forecast created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BudgetVersion')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function rollForward(RollForwardRequest $request, string $id): JsonResponse
    {
        $base = BudgetVersion::query()->findOrFail($id);
        $this->authorize('view', $base);
        $this->authorize('create', BudgetVersion::class);

        return (new VersionResource($this->revisions->rollForward($base, $request->validated(), $this->userId())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/budgeting/versions/{id}/audit', operationId: 'listBudgetVersionAudit', description: 'The audit trail of a version: revisions, transfers, grid saves, imports and scenarios, newest first.', summary: 'Audit trail of a budget version', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], responses: [
        new OA\Response(response: 200, description: 'Audit events', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
    ])]
    public function audit(string $id): AnonymousResourceCollection
    {
        $version = BudgetVersion::query()->findOrFail($id);
        $this->authorize('view', $version);

        return AuditEventResource::collection(
            BudgetAuditEvent::query()->where('subject_type', BudgetAuditEvent::SubjectVersion)->where('subject_id', $version->id)->orderByDesc('created_at')->orderByDesc('id')->paginate($this->perPage()),
        );
    }
}
