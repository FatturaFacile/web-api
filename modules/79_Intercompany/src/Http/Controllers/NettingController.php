<?php

declare(strict_types=1);

namespace Modules\Intercompany\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Intercompany\Http\Requests\CancelNettingRequest;
use Modules\Intercompany\Http\Requests\ProposeNettingRequest;
use Modules\Intercompany\Http\Requests\VersionedRequest;
use Modules\Intercompany\Http\Resources\NettingRunResource;
use Modules\Intercompany\Models\NettingRun;
use Modules\Intercompany\Services\NettingService;
use Modules\Intercompany\Support\GroupScope;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Netting (compensazione delle partite) of the open intercompany items of two entities.
 */
final class NettingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly GroupScope $scope,
        private readonly NettingService $netting,
    ) {}

    #[OA\Get(path: '/api/v1/intercompany/groups/{groupId}/netting', operationId: 'intercompanyListNetting', summary: 'Netting runs of a group', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'groupId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Netting runs')])]
    public function index(Request $request, string $groupId): JsonResponse
    {
        Gate::authorize('intercompany.view');
        $group = $this->scope->group($groupId);
        $this->scope->assertReadable($request->user(), $group);
        $runs = NettingRun::query()->where('group_id', $group->id)->when($request->query('status'), fn ($q, $value) => $q->where('status', $value))->orderByDesc('created_at')->get();

        return $this->successResponse(NettingRunResource::collection($runs), 'Netting runs');
    }

    #[OA\Post(path: '/api/v1/intercompany/groups/{groupId}/netting', operationId: 'intercompanyProposeNetting', summary: 'Propose the netting of two entities', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'groupId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'Draft netting')])]
    public function store(ProposeNettingRequest $request, string $groupId): JsonResponse
    {
        $group = $this->scope->group($groupId);
        $data = $request->validated();
        $run = $this->netting->propose($request->user(), $group, $data['entity_a_tenant_id'], $data['entity_b_tenant_id'], CarbonImmutable::parse($data['as_of_date']), $data['currency'] ?? null);

        return $this->successResponse(new NettingRunResource($run), 'Netting proposed', Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/intercompany/netting/{id}', operationId: 'intercompanyShowNetting', summary: 'A netting run with its items', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Netting run')])]
    public function show(Request $request, string $id): JsonResponse
    {
        Gate::authorize('intercompany.view');
        $run = NettingRun::query()->findOrFail($id);
        $this->scope->assertReadable($request->user(), $this->scope->group((string) $run->group_id));

        return $this->successResponse(new NettingRunResource($run), 'Netting run');
    }

    #[OA\Post(path: '/api/v1/intercompany/netting/{id}/confirm', operationId: 'intercompanyConfirmNetting', summary: 'Confirm and apply a netting', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Netting run')])]
    public function confirm(VersionedRequest $request, string $id): JsonResponse
    {
        $run = NettingRun::query()->findOrFail($id);
        $group = $this->scope->group((string) $run->group_id);

        return $this->successResponse(new NettingRunResource($this->netting->confirm($request->user(), $group, $run, ($request->integer('expected_version') ?: null))), 'Netting confirmed');
    }

    #[OA\Post(path: '/api/v1/intercompany/netting/{id}/cancel', operationId: 'intercompanyCancelNetting', summary: 'Cancel a draft netting', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Netting run')])]
    public function cancel(CancelNettingRequest $request, string $id): JsonResponse
    {
        $run = NettingRun::query()->findOrFail($id);
        $group = $this->scope->group((string) $run->group_id);

        return $this->successResponse(new NettingRunResource($this->netting->cancel($request->user(), $group, $run, (string) $request->validated('reason'), ($request->integer('expected_version') ?: null))), 'Netting cancelled');
    }
}
