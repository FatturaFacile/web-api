<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\FranchiseNetwork\Http\Controllers\Concerns\InteractsWithFranchiseNetwork;
use Modules\FranchiseNetwork\Http\Requests\CatalogPushRequest;
use Modules\FranchiseNetwork\Http\Requests\ListFiltersRequest;
use Modules\FranchiseNetwork\Http\Resources\PushResource;
use Modules\FranchiseNetwork\Models\CatalogPush;
use Modules\FranchiseNetwork\Services\CatalogPushService;
use OpenApi\Attributes as OA;

/**
 * Central distribution of catalogue, price lists, promotions and loyalty campaigns to the stores, with the sync monitor.
 */
final class CatalogPushController extends Controller
{
    use ApiResponse;
    use InteractsWithFranchiseNetwork;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(private readonly CatalogPushService $pushes) {}

    #[OA\Get(path: '/api/v1/franchise-network/pushes', operationId: 'franchiseNetworkListPushes', summary: 'List the central pushes with their delivery counters', security: self::SECURITY, tags: ['Franchise Network'], responses: [new OA\Response(response: 200, description: 'Pushes, paginated')])]
    public function index(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('manage', CatalogPush::class);

        $page = CatalogPush::query()->withCount('targets')
            ->with(['targets.store'])
            ->when($request->input('status'), fn ($rows, $status) => $rows->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate($this->perPage(20));

        return $this->paged($page, PushResource::class);
    }

    #[OA\Get(path: '/api/v1/franchise-network/pushes/{id}', operationId: 'franchiseNetworkShowPush', summary: 'Show a push with the state of every store', security: self::SECURITY, tags: ['Franchise Network'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Push')])]
    public function show(string $id): JsonResponse
    {
        $push = CatalogPush::query()->with('targets.store')->findOrFail($id);
        Gate::authorize('manage', CatalogPush::class);

        return $this->successResponse(new PushResource($push), $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/franchise-network/pushes', operationId: 'franchiseNetworkCreatePush', summary: 'Create a push and, with dispatch, deliver it to the stores at once', security: self::SECURITY, tags: ['Franchise Network'], responses: [new OA\Response(response: 201, description: 'Push created')])]
    public function store(CatalogPushRequest $request): JsonResponse
    {
        Gate::authorize('create', CatalogPush::class);
        $push = $this->pushes->create($request->validated(), $this->userId());

        if ($request->boolean('dispatch', true)) {
            $push = $this->pushes->dispatch($push, $this->userId());
        }

        return $this->successResponse(new PushResource($push->load('targets.store')), $this->message('push_created'), 201);
    }

    #[OA\Post(path: '/api/v1/franchise-network/pushes/{id}/dispatch', operationId: 'franchiseNetworkDispatchPush', summary: 'Deliver the pending stores of a push', security: self::SECURITY, tags: ['Franchise Network'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Push delivered')])]
    public function deliver(string $id): JsonResponse
    {
        $push = CatalogPush::query()->findOrFail($id);
        Gate::authorize('update', $push);

        return $this->successResponse(new PushResource($this->pushes->dispatch($push, $this->userId())->load('targets.store')), $this->message('push_dispatched'));
    }

    #[OA\Post(path: '/api/v1/franchise-network/pushes/{id}/retry', operationId: 'franchiseNetworkRetryPush', summary: 'Retry the failed stores of a push', security: self::SECURITY, tags: ['Franchise Network'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Failed stores retried')])]
    public function retry(string $id): JsonResponse
    {
        $push = CatalogPush::query()->findOrFail($id);
        Gate::authorize('update', $push);

        return $this->successResponse(new PushResource($this->pushes->retryFailed($push, $this->userId())->load('targets.store')), $this->message('push_retried'));
    }
}
