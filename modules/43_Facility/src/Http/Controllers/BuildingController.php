<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Facility\Http\Controllers\Concerns\InteractsWithFacility;
use Modules\Facility\Http\Requests\BuildingRequest;
use Modules\Facility\Http\Resources\BuildingResource;
use Modules\Facility\Models\FacilityBuilding;
use Modules\Facility\Services\SpaceService;

/**
 * Buildings of the company. Operations are documented in FacilityRegisterOpenApi.
 */
final class BuildingController extends Controller
{
    use InteractsWithFacility;

    public function __construct(private readonly SpaceService $spaces) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', FacilityBuilding::class);

        return BuildingResource::collection(
            FacilityBuilding::query()
                ->when($request->filled('active'), static fn ($query) => $query->where('is_active', $request->boolean('active')))
                ->when($request->filled('q'), static fn ($query) => $query->where(static fn ($inner) => $inner->where('name', 'like', '%'.$request->query('q').'%')->orWhere('code', 'like', '%'.$request->query('q').'%')))
                ->orderBy('code')
                ->gridPaginate($this->perPage())
        );
    }

    public function store(BuildingRequest $request): JsonResponse
    {
        $this->authorize('manage', FacilityBuilding::class);

        return (new BuildingResource($this->spaces->createBuilding($request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(string $id): BuildingResource
    {
        $building = FacilityBuilding::query()->findOrFail($id);
        $this->authorize('view', $building);

        return new BuildingResource($building);
    }

    public function update(BuildingRequest $request, string $id): BuildingResource
    {
        $building = FacilityBuilding::query()->findOrFail($id);
        $this->authorize('manage', $building);

        return new BuildingResource($this->spaces->updateBuilding($building, $request->validated()));
    }

    public function destroy(string $id): Response
    {
        $building = FacilityBuilding::query()->findOrFail($id);
        $this->authorize('manage', $building);
        $this->spaces->deleteBuilding($building);

        return response()->noContent();
    }
}
