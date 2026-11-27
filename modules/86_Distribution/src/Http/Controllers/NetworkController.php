<?php

declare(strict_types=1);

namespace Modules\Distribution\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Distribution\Http\Controllers\Concerns\InteractsWithDistribution;
use Modules\Distribution\Http\Requests\DistributionFilterRequest;
use Modules\Distribution\Http\Requests\SaveLaneRequest;
use Modules\Distribution\Http\Requests\SaveWarehouseProfileRequest;
use Modules\Distribution\Http\Resources\LaneResource;
use Modules\Distribution\Http\Resources\WarehouseProfileResource;
use Modules\Distribution\Models\DistributionLane;
use Modules\Distribution\Models\WarehouseProfile;
use Modules\Distribution\Services\NetworkService;
use Modules\Inventory\Models\Warehouse;
use OpenApi\Attributes as OA;

/**
 * The warehouse network: the profile of every warehouse (role, hub, third party, consignment terms) and the distribution lanes.
 */
final class NetworkController extends Controller
{
    use ApiResponse;
    use InteractsWithDistribution;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/distribution/network/warehouses', operationId: 'distributionNetworkWarehouses', summary: 'Warehouses with their network profile', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Warehouses and profiles')])]
    public function warehouses(DistributionFilterRequest $request, NetworkService $network): JsonResponse
    {
        Gate::authorize('viewAny', WarehouseProfile::class);

        $warehouses = Warehouse::query()
            ->when($request->input('q'), fn ($rows, $term) => $rows->where(fn ($inner) => $inner->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower((string) $term).'%'])->orWhereRaw('LOWER(code) LIKE ?', ['%'.mb_strtolower((string) $term).'%'])))
            ->orderBy('code')
            ->get();

        $profiles = WarehouseProfile::query()->whereIn('warehouse_id', $warehouses->pluck('id')->all())->get()->keyBy('warehouse_id');
        $roles = $network->roles($warehouses);

        return $this->successResponse($warehouses->map(static fn (Warehouse $warehouse): array => [
            'id' => (string) $warehouse->id,
            'code' => (string) $warehouse->code,
            'name' => (string) $warehouse->name,
            'warehouse_type' => (string) $warehouse->warehouse_type,
            'is_active' => (bool) $warehouse->is_active,
            'role' => $roles[(string) $warehouse->id]->value,
            'profile' => $profiles->has($warehouse->id) ? (new WarehouseProfileResource($profiles->get($warehouse->id)))->resolve() : null,
        ])->values()->all(), $this->message('listed'));
    }

    #[OA\Put(path: '/api/v1/distribution/network/warehouses/{id}/profile', operationId: 'distributionSaveWarehouseProfile', summary: 'Save the network profile of a warehouse', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Profile saved')])]
    public function saveProfile(string $id, SaveWarehouseProfileRequest $request, NetworkService $network): JsonResponse
    {
        $warehouse = Warehouse::query()->findOrFail($id);
        Gate::authorize('update', $warehouse);

        $profile = $network->saveProfile($warehouse, $request->validated());

        return $this->successResponse(new WarehouseProfileResource($profile), $this->message('saved'));
    }

    #[OA\Get(path: '/api/v1/distribution/lanes', operationId: 'distributionListLanes', summary: 'List the distribution lanes', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Lanes')])]
    public function lanes(DistributionFilterRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', DistributionLane::class);

        $page = DistributionLane::query()
            ->with(['sourceWarehouse', 'destinationWarehouse'])
            ->when($request->input('source_warehouse_id'), static fn ($rows, $id) => $rows->where('source_warehouse_id', $id))
            ->when($request->input('destination_warehouse_id'), static fn ($rows, $id) => $rows->where('destination_warehouse_id', $id))
            ->orderBy('source_warehouse_id')
            ->orderBy('lead_time_days')
            ->paginate($this->perPage(100));

        return $this->paged($page, LaneResource::class);
    }

    #[OA\Post(path: '/api/v1/distribution/lanes', operationId: 'distributionCreateLane', summary: 'Create a distribution lane', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 201, description: 'Lane created')])]
    public function storeLane(SaveLaneRequest $request): JsonResponse
    {
        Gate::authorize('create', DistributionLane::class);

        $lane = DistributionLane::create($request->validated() + ['transport_mode' => 'road', 'lead_time_hours' => 0, 'cost_per_unit' => 0]);

        return $this->createdResponse(new LaneResource($lane->load(['sourceWarehouse', 'destinationWarehouse'])), $this->message('saved'));
    }

    #[OA\Put(path: '/api/v1/distribution/lanes/{id}', operationId: 'distributionUpdateLane', summary: 'Update a distribution lane', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Lane updated')])]
    public function updateLane(string $id, SaveLaneRequest $request): JsonResponse
    {
        $lane = DistributionLane::query()->findOrFail($id);
        Gate::authorize('update', $lane);
        $lane->update($request->validated());

        return $this->successResponse(new LaneResource($lane->refresh()->load(['sourceWarehouse', 'destinationWarehouse'])), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/distribution/lanes/{id}', operationId: 'distributionDeleteLane', summary: 'Delete a distribution lane', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Lane deleted')])]
    public function destroyLane(string $id): JsonResponse
    {
        $lane = DistributionLane::query()->findOrFail($id);
        Gate::authorize('delete', $lane);
        // Lanes are configuration: a deleted lane must not block a new one for the same route (unique index).
        $lane->forceDelete();

        return $this->successResponse(null, $this->message('deleted'));
    }
}
