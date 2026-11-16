<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Budgeting\Http\Controllers\Concerns\InteractsWithBudgeting;
use Modules\Budgeting\Http\Requests\LineRequest;
use Modules\Budgeting\Http\Requests\SpreadRequest;
use Modules\Budgeting\Http\Resources\LineResource;
use Modules\Budgeting\Models\BudgetLine;
use Modules\Budgeting\Models\BudgetVersion;
use Modules\Budgeting\Services\LineService;
use OpenApi\Attributes as OA;

/**
 * Budget lines by account, cost centre, project and period, and the spread of an amount over periods.
 */
final class LineController extends Controller
{
    use InteractsWithBudgeting;

    public function __construct(private readonly LineService $lines) {}

    #[OA\Get(path: '/api/v1/budgeting/versions/{id}/lines', operationId: 'listBudgetLines', description: 'Lines of a version by account and period. Filters: account_id, cost_center_id, project_id, period.', summary: 'List budget lines', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId'), new OA\Parameter(name: 'account_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'cost_center_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'project_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'period', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 12))], responses: [
        new OA\Response(response: 200, description: 'Lines', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/BudgetLine'))])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function index(Request $request, string $id): AnonymousResourceCollection
    {
        $version = BudgetVersion::query()->findOrFail($id);
        $this->authorize('view', $version);
        $filters = $request->validate(['account_id' => ['nullable', 'uuid'], 'cost_center_id' => ['nullable', 'uuid'], 'project_id' => ['nullable', 'uuid'], 'period' => ['nullable', 'integer', 'min:1', 'max:12']]);

        return LineResource::collection(
            BudgetLine::query()
                ->where('version_id', $version->id)
                ->when(isset($filters['account_id']), static fn ($query) => $query->where('account_id', $filters['account_id']))
                ->when(isset($filters['cost_center_id']), static fn ($query) => $query->where('cost_center_id', $filters['cost_center_id']))
                ->when(isset($filters['project_id']), static fn ($query) => $query->where('project_id', $filters['project_id']))
                ->when(isset($filters['period']), static fn ($query) => $query->where('period', $filters['period']))
                ->orderBy('account_id')
                ->orderBy('period')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/budgeting/versions/{id}/lines', operationId: 'storeBudgetLine', description: 'Save the amount of an account (and cost centre and project) in a fiscal period of a draft version. Saving the same combination again replaces the amount. The account, cost centre and project must exist in the ledger of the company.', summary: 'Save a budget line', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BudgetLineRequest')), tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], responses: [
        new OA\Response(response: 201, description: 'Line saved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BudgetLine')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function store(LineRequest $request, string $id): JsonResponse
    {
        $version = BudgetVersion::query()->findOrFail($id);
        $this->authorize('update', $version);

        return (new LineResource($this->lines->save($version, $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/budgeting/lines/{id}', operationId: 'updateBudgetLine', description: 'Change a line of a draft version.', summary: 'Update a budget line', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BudgetLineRequest')), tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], responses: [
        new OA\Response(response: 200, description: 'Line updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BudgetLine')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function update(LineRequest $request, string $id): LineResource
    {
        $line = BudgetLine::query()->findOrFail($id);
        $this->authorize('update', $line);

        return new LineResource($this->lines->update($line, $request->validated()));
    }

    #[OA\Delete(path: '/api/v1/budgeting/lines/{id}', operationId: 'deleteBudgetLine', description: 'Delete a line of a draft version.', summary: 'Delete a budget line', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], responses: [
        new OA\Response(response: 204, description: 'Line deleted'),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $line = BudgetLine::query()->findOrFail($id);
        $this->authorize('delete', $line);
        $this->lines->delete($line);

        return response()->noContent();
    }

    #[OA\Post(path: '/api/v1/budgeting/versions/{id}/spread', operationId: 'spreadBudgetAmount', description: 'Allocate an amount over a range of periods of one account (and cost centre and project) in a draft version, replacing the lines already there. Method even splits it equally, seasonal follows the seasonal weights of the version, percentage uses one percentage per period of the range (adding up to 100). The lines always add up to the amount to the cent.', summary: 'Spread an amount over periods', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BudgetSpreadRequest')), tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], responses: [
        new OA\Response(response: 201, description: 'Lines written', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/BudgetLine'))])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function spread(SpreadRequest $request, string $id): JsonResponse
    {
        $version = BudgetVersion::query()->findOrFail($id);
        $this->authorize('update', $version);

        return LineResource::collection(collect($this->lines->spread($version, $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
