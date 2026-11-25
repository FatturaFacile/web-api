<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\FranchiseNetwork\Http\Controllers\Concerns\InteractsWithFranchiseNetwork;
use Modules\FranchiseNetwork\Http\Requests\ListFiltersRequest;
use Modules\FranchiseNetwork\Http\Requests\SaveStoreRequest;
use Modules\FranchiseNetwork\Http\Resources\StoreResource;
use Modules\FranchiseNetwork\Models\FranchiseStore;
use Modules\FranchiseNetwork\Services\StoreService;
use OpenApi\Attributes as OA;

/**
 * Franchisee stores and directly operated stores of the network.
 */
final class StoreController extends Controller
{
    use ApiResponse;
    use InteractsWithFranchiseNetwork;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(private readonly StoreService $stores) {}

    #[OA\Get(path: '/api/v1/franchise-network/stores', operationId: 'franchiseNetworkListStores', summary: 'List the stores of the network', security: self::SECURITY, tags: ['Franchise Network'], responses: [new OA\Response(response: 200, description: 'Stores, paginated')])]
    public function index(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', FranchiseStore::class);

        $page = $this->visibleStores(FranchiseStore::query())
            ->when($request->input('q'), fn ($rows, $term) => $rows->where(fn ($inner) => $inner->whereRaw('LOWER(code) LIKE ?', ['%'.mb_strtolower((string) $term).'%'])->orWhereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower((string) $term).'%'])))
            ->when($request->input('status'), fn ($rows, $status) => $rows->where('status', $status))
            ->orderBy('code')
            ->paginate($this->perPage(50));

        return $this->paged($page, StoreResource::class);
    }

    #[OA\Get(path: '/api/v1/franchise-network/stores/{id}', operationId: 'franchiseNetworkShowStore', summary: 'Show a store', security: self::SECURITY, tags: ['Franchise Network'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Store')])]
    public function show(string $id): JsonResponse
    {
        $store = $this->visibleStores(FranchiseStore::query())->findOrFail($id);
        Gate::authorize('view', $store);

        return $this->successResponse(new StoreResource($store), $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/franchise-network/stores', operationId: 'franchiseNetworkCreateStore', summary: 'Add a store to the network', security: self::SECURITY, tags: ['Franchise Network'], responses: [new OA\Response(response: 201, description: 'Store created')])]
    public function store(SaveStoreRequest $request): JsonResponse
    {
        Gate::authorize('create', FranchiseStore::class);

        return $this->successResponse(new StoreResource($this->stores->save($request->validated(), null, $this->userId())), $this->message('saved'), 201);
    }

    #[OA\Put(path: '/api/v1/franchise-network/stores/{id}', operationId: 'franchiseNetworkUpdateStore', summary: 'Update a store', security: self::SECURITY, tags: ['Franchise Network'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Store updated')])]
    public function update(SaveStoreRequest $request, string $id): JsonResponse
    {
        $store = FranchiseStore::query()->findOrFail($id);
        Gate::authorize('update', $store);

        return $this->successResponse(new StoreResource($this->stores->save($request->validated(), $store, $this->userId())), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/franchise-network/stores/{id}', operationId: 'franchiseNetworkDeleteStore', summary: 'Delete a store without an active agreement', security: self::SECURITY, tags: ['Franchise Network'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Store deleted')])]
    public function destroy(string $id): JsonResponse
    {
        $store = FranchiseStore::query()->findOrFail($id);
        Gate::authorize('delete', $store);
        $this->stores->delete($store);

        return $this->successResponse(null, $this->message('deleted'));
    }

    #[OA\Post(path: '/api/v1/franchise-network/stores/{id}/link-code', operationId: 'franchiseNetworkIssueLinkCode', summary: 'Issue the one-time code the franchisee redeems to link its company to the store', security: self::SECURITY, tags: ['Franchise Network'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Link code')])]
    public function linkCode(string $id): JsonResponse
    {
        $store = FranchiseStore::query()->findOrFail($id);
        Gate::authorize('update', $store);

        return $this->successResponse(['code' => $this->stores->issueLinkCode($store)], $this->message('link_code_issued'));
    }
}
