<?php

declare(strict_types=1);

namespace Modules\Sustainability\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Sustainability\Http\Controllers\Concerns\InteractsWithSustainability;
use Modules\Sustainability\Http\Requests\ReductionTargetRequest;
use Modules\Sustainability\Http\Resources\ReductionTargetResource;
use Modules\Sustainability\Models\EsgReductionTarget;
use Modules\Sustainability\Services\ReductionTargetService;
use OpenApi\Attributes as OA;

/**
 * Emission reduction targets with their progress against the frozen baseline.
 */
final class ReductionTargetController extends Controller
{
    use InteractsWithSustainability;

    public function __construct(private readonly ReductionTargetService $targets) {}

    #[OA\Get(path: '/api/v1/sustainability/targets', operationId: 'listEsgReductionTargets', description: 'Reduction targets with progress. Filter: status.', summary: 'List reduction targets', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], responses: [
        new OA\Response(response: 200, description: 'Targets', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SustainabilityTarget'))])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', EsgReductionTarget::class);
        $filters = $request->validate(['status' => ['nullable', Rule::in(EsgReductionTarget::Statuses)]]);

        return ReductionTargetResource::collection(
            EsgReductionTarget::query()
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->orderBy('target_year')
                ->orderBy('name')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/sustainability/targets', operationId: 'storeEsgReductionTarget', description: 'Create a draft reduction target (scope_1, scope_2, scope_3 or total, a percentage against the baseline year).', summary: 'Create a reduction target', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SustainabilityTargetRequest')), tags: ['Sustainability'], responses: [
        new OA\Response(response: 201, description: 'Target created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilityTarget')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityUnprocessable', response: 422),
    ])]
    public function store(ReductionTargetRequest $request): JsonResponse
    {
        $this->authorize('create', EsgReductionTarget::class);

        return (new ReductionTargetResource($this->targets->create($this->tenantId(), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/sustainability/targets/{id}', operationId: 'showEsgReductionTarget', description: 'Return a reduction target with its progress.', summary: 'Show a reduction target', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], parameters: [new OA\Parameter(ref: '#/components/parameters/SustainabilityId')], responses: [
        new OA\Response(response: 200, description: 'Target', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilityTarget')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityNotFound', response: 404),
    ])]
    public function show(string $id): ReductionTargetResource
    {
        $target = EsgReductionTarget::query()->findOrFail($id);
        $this->authorize('view', $target);

        return new ReductionTargetResource($target);
    }

    #[OA\Put(path: '/api/v1/sustainability/targets/{id}', operationId: 'updateEsgReductionTarget', description: 'Change a target. Only a draft target can change its scope, years and percentage; the name and the notes can always change.', summary: 'Update a reduction target', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SustainabilityTargetRequest')), tags: ['Sustainability'], parameters: [new OA\Parameter(ref: '#/components/parameters/SustainabilityId')], responses: [
        new OA\Response(response: 200, description: 'Target updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilityTarget')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SustainabilityUnprocessable', response: 422),
    ])]
    public function update(ReductionTargetRequest $request, string $id): ReductionTargetResource
    {
        $target = EsgReductionTarget::query()->findOrFail($id);
        $this->authorize('update', $target);

        return new ReductionTargetResource($this->targets->update($target, $request->validated()));
    }

    #[OA\Post(path: '/api/v1/sustainability/targets/{id}/activate', operationId: 'activateEsgReductionTarget', description: 'Activate a draft target: the baseline is read from the inventory of the baseline year (it must have emissions) and frozen.', summary: 'Activate a reduction target', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], parameters: [new OA\Parameter(ref: '#/components/parameters/SustainabilityId')], responses: [
        new OA\Response(response: 200, description: 'Target activated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilityTarget')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SustainabilityUnprocessable', response: 422),
    ])]
    public function activate(string $id): ReductionTargetResource
    {
        $target = EsgReductionTarget::query()->findOrFail($id);
        $this->authorize('update', $target);

        return new ReductionTargetResource($this->targets->activate($target));
    }

    #[OA\Post(path: '/api/v1/sustainability/targets/{id}/evaluate', operationId: 'evaluateEsgReductionTarget', description: 'Decide an active target: achieved when the latest inventory year shows the committed reduction, missed when the target year is over without it; before that the request is refused.', summary: 'Evaluate a reduction target', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], parameters: [new OA\Parameter(ref: '#/components/parameters/SustainabilityId')], responses: [
        new OA\Response(response: 200, description: 'Target evaluated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilityTarget')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SustainabilityUnprocessable', response: 422),
    ])]
    public function evaluate(string $id): ReductionTargetResource
    {
        $target = EsgReductionTarget::query()->findOrFail($id);
        $this->authorize('update', $target);

        return new ReductionTargetResource($this->targets->evaluate($target));
    }

    #[OA\Post(path: '/api/v1/sustainability/targets/{id}/cancel', operationId: 'cancelEsgReductionTarget', description: 'Cancel a draft or active target.', summary: 'Cancel a reduction target', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], parameters: [new OA\Parameter(ref: '#/components/parameters/SustainabilityId')], responses: [
        new OA\Response(response: 200, description: 'Target cancelled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilityTarget')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SustainabilityUnprocessable', response: 422),
    ])]
    public function cancel(string $id): ReductionTargetResource
    {
        $target = EsgReductionTarget::query()->findOrFail($id);
        $this->authorize('update', $target);

        return new ReductionTargetResource($this->targets->cancel($target));
    }
}
