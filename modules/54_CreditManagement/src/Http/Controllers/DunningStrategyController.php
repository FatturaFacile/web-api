<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\CreditManagement\Http\Controllers\Concerns\InteractsWithCreditManagement;
use Modules\CreditManagement\Http\Requests\DunningStrategyRequest;
use Modules\CreditManagement\Http\Resources\DunningStrategyResource;
use Modules\CreditManagement\Models\CreditDunningStrategy;
use Modules\CreditManagement\Services\DunningStrategyService;
use OpenApi\Attributes as OA;

/**
 * Dunning strategies and their steps.
 */
final class DunningStrategyController extends Controller
{
    use InteractsWithCreditManagement;

    public function __construct(private readonly DunningStrategyService $strategies) {}

    #[OA\Get(path: '/api/v1/credit-management/dunning-strategies', operationId: 'listCreditDunningStrategies', description: 'Dunning strategies with their steps.', summary: 'List dunning strategies', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Credit management'], responses: [
        new OA\Response(response: 200, description: 'List dunning strategies', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CreditDunningStrategy'))])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
    ])]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CreditDunningStrategy::class);

        return DunningStrategyResource::collection(CreditDunningStrategy::query()->with('steps')->where('tenant_id', $this->tenantId())->orderByDesc('is_default')->orderBy('name')->get());
    }

    #[OA\Post(path: '/api/v1/credit-management/dunning-strategies', operationId: 'storeCreditDunningStrategy', description: 'Create a strategy. Steps are numbered by their position and their days after the due date never go down. The first strategy is the default.', summary: 'Create a dunning strategy', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreditDunningStrategyRequest')), tags: ['Credit management'], responses: [
        new OA\Response(response: 201, description: 'Create a dunning strategy', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditDunningStrategy')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditUnprocessable', response: 422),
    ])]
    public function store(DunningStrategyRequest $request): JsonResponse
    {
        $this->authorize('create', CreditDunningStrategy::class);

        return (new DunningStrategyResource($this->strategies->create($this->tenantId(), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/credit-management/dunning-strategies/{id}', operationId: 'showCreditDunningStrategy', description: 'Return a strategy with its steps.', summary: 'Show a dunning strategy', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Credit management'], parameters: [new OA\Parameter(ref: '#/components/parameters/CreditId')], responses: [
        new OA\Response(response: 200, description: 'Show a dunning strategy', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditDunningStrategy')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditNotFound', response: 404),
    ])]
    public function show(string $id): DunningStrategyResource
    {
        $strategy = CreditDunningStrategy::query()->with('steps')->findOrFail($id);
        $this->authorize('view', $strategy);

        return new DunningStrategyResource($strategy);
    }

    #[OA\Put(path: '/api/v1/credit-management/dunning-strategies/{id}', operationId: 'updateCreditDunningStrategy', description: 'Change a strategy; steps given are replaced, except when an open case already ran some of them.', summary: 'Update a dunning strategy', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreditDunningStrategyRequest')), tags: ['Credit management'], parameters: [new OA\Parameter(ref: '#/components/parameters/CreditId')], responses: [
        new OA\Response(response: 200, description: 'Update a dunning strategy', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditDunningStrategy')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CreditUnprocessable', response: 422),
    ])]
    public function update(DunningStrategyRequest $request, string $id): DunningStrategyResource
    {
        $strategy = CreditDunningStrategy::query()->findOrFail($id);
        $this->authorize('update', $strategy);

        return new DunningStrategyResource($this->strategies->update($strategy, $request->validated()));
    }

    #[OA\Delete(path: '/api/v1/credit-management/dunning-strategies/{id}', operationId: 'deleteCreditDunningStrategy', description: 'Delete a strategy that no open case uses (soft delete).', summary: 'Delete a dunning strategy', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Credit management'], parameters: [new OA\Parameter(ref: '#/components/parameters/CreditId')], responses: [
        new OA\Response(response: 204, description: 'Delete a dunning strategy'),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CreditUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $strategy = CreditDunningStrategy::query()->findOrFail($id);
        $this->authorize('delete', $strategy);
        $this->strategies->delete($strategy);

        return response()->noContent();
    }
}
