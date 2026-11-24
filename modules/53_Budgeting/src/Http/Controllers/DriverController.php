<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Budgeting\Http\Controllers\Concerns\InteractsWithBudgeting;
use Modules\Budgeting\Http\Requests\DriverRequest;
use Modules\Budgeting\Http\Resources\DriverResource;
use Modules\Budgeting\Http\Resources\LineResource;
use Modules\Budgeting\Models\BudgetDriver;
use Modules\Budgeting\Models\BudgetVersion;
use Modules\Budgeting\Services\DriverService;
use OpenApi\Attributes as OA;

/**
 * Planning drivers (quantity times rate) that generate budget lines.
 */
final class DriverController extends Controller
{
    use InteractsWithBudgeting;

    public function __construct(private readonly DriverService $drivers) {}

    #[OA\Get(path: '/api/v1/budgeting/versions/{id}/drivers', operationId: 'listBudgetDrivers', description: 'Planning drivers of a version.', summary: 'List drivers', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], responses: [
        new OA\Response(response: 200, description: 'Drivers', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/BudgetDriver'))])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
    ])]
    public function index(string $id): AnonymousResourceCollection
    {
        $version = BudgetVersion::query()->findOrFail($id);
        $this->authorize('view', $version);

        return DriverResource::collection(BudgetDriver::query()->where('version_id', $version->id)->orderBy('name')->paginate($this->perPage()));
    }

    #[OA\Post(path: '/api/v1/budgeting/versions/{id}/drivers', operationId: 'storeBudgetDriver', description: 'Add a driver to a draft version. The driver does not write lines until it is generated.', summary: 'Create a driver', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BudgetDriverRequest')), tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], responses: [
        new OA\Response(response: 201, description: 'Driver created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BudgetDriver')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function store(DriverRequest $request, string $id): JsonResponse
    {
        $version = BudgetVersion::query()->findOrFail($id);
        $this->authorize('update', $version);

        return (new DriverResource($this->drivers->create($version, $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/budgeting/drivers/{id}', operationId: 'updateBudgetDriver', description: 'Change a driver of a draft version. Its lines are rewritten when it is generated again.', summary: 'Update a driver', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BudgetDriverRequest')), tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], responses: [
        new OA\Response(response: 200, description: 'Driver updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BudgetDriver')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function update(DriverRequest $request, string $id): DriverResource
    {
        $driver = BudgetDriver::query()->findOrFail($id);
        $this->authorize('update', $driver);

        return new DriverResource($this->drivers->update($driver, $request->validated()));
    }

    #[OA\Delete(path: '/api/v1/budgeting/drivers/{id}', operationId: 'deleteBudgetDriver', description: 'Delete a driver of a draft version together with the lines it generated.', summary: 'Delete a driver', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], responses: [
        new OA\Response(response: 204, description: 'Driver deleted'),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $driver = BudgetDriver::query()->findOrFail($id);
        $this->authorize('delete', $driver);
        $this->drivers->delete($driver);

        return response()->noContent();
    }

    #[OA\Post(path: '/api/v1/budgeting/drivers/{id}/generate', operationId: 'generateBudgetDriver', description: 'Write the budget lines of the driver: quantity times rate spread over its periods. The lines the driver wrote before are replaced; an inactive driver writes none.', summary: 'Generate the lines of a driver', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], responses: [
        new OA\Response(response: 200, description: 'Lines written', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/BudgetLine'))])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function generate(string $id): AnonymousResourceCollection
    {
        $driver = BudgetDriver::query()->findOrFail($id);
        $this->authorize('update', $driver);

        return LineResource::collection(collect($this->drivers->generate($driver)));
    }

    #[OA\Post(path: '/api/v1/budgeting/versions/{id}/generate-drivers', operationId: 'generateBudgetDrivers', description: 'Generate every active driver of a draft version.', summary: 'Generate all drivers of a version', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], responses: [
        new OA\Response(response: 200, description: 'Generation result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'drivers', type: 'integer'), new OA\Property(property: 'lines', type: 'integer')], type: 'object')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function generateAll(string $id): JsonResponse
    {
        $version = BudgetVersion::query()->findOrFail($id);
        $this->authorize('update', $version);

        return response()->json(['data' => $this->drivers->generateAll($version)]);
    }
}
