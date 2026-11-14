<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Modules\Facility\Http\Controllers\Concerns\InteractsWithFacility;
use Modules\Facility\Http\Requests\SpaceRequest;
use Modules\Facility\Http\Resources\SpaceResource;
use Modules\Facility\Models\FacilityBuilding;
use Modules\Facility\Models\FacilitySpace;
use Modules\Facility\Services\BookingService;
use Modules\Facility\Services\SpaceService;

/**
 * Floors and spaces of the buildings, the nested tree and the search for a free bookable space. Operations are
 * documented in FacilityRegisterOpenApi.
 */
final class SpaceController extends Controller
{
    use InteractsWithFacility;

    public function __construct(private readonly SpaceService $spaces, private readonly BookingService $bookings) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', FacilitySpace::class);
        $filters = $request->validate([
            'building_id' => ['nullable', 'uuid'],
            'parent_id' => ['nullable', 'uuid'],
            'kind' => ['nullable', Rule::in(FacilitySpace::Kinds)],
            'bookable' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return SpaceResource::collection(
            FacilitySpace::query()
                ->with('building:id,code,name')
                ->when(isset($filters['building_id']), static fn ($query) => $query->where('building_id', $filters['building_id']))
                ->when(isset($filters['parent_id']), static fn ($query) => $query->where('parent_id', $filters['parent_id']))
                ->when(isset($filters['kind']), static fn ($query) => $query->where('kind', $filters['kind']))
                ->when(isset($filters['bookable']), static fn ($query) => $query->where('is_bookable', $request->boolean('bookable')))
                ->when(isset($filters['active']), static fn ($query) => $query->where('is_active', $request->boolean('active')))
                ->when(isset($filters['q']), static fn ($query) => $query->where(static fn ($inner) => $inner->where('name', 'like', '%'.$filters['q'].'%')->orWhere('code', 'like', '%'.$filters['q'].'%')))
                ->orderBy('building_id')
                ->orderBy('code')
                ->gridPaginate($this->perPage())
        );
    }

    public function tree(string $id): JsonResponse
    {
        $building = FacilityBuilding::query()->findOrFail($id);
        $this->authorize('view', $building);

        $format = function (array $nodes) use (&$format): array {
            return array_map(static fn (array $node): array => [...(new SpaceResource($node['space']))->resolve(), 'children' => $format($node['children'])], $nodes);
        };

        return response()->json(['data' => $format($this->spaces->tree($building))]);
    }

    public function available(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', FacilitySpace::class);
        $filters = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'building_id' => ['nullable', 'uuid'],
            'kind' => ['nullable', Rule::in(FacilitySpace::BookableKinds)],
            'min_capacity' => ['nullable', 'integer', 'min:1'],
        ]);

        return SpaceResource::collection($this->bookings->availableSpaces(
            $this->parseInstant($filters['starts_at']),
            $this->parseInstant($filters['ends_at']),
            Arr::only($filters, ['building_id', 'kind', 'min_capacity']),
        ));
    }

    public function store(SpaceRequest $request): JsonResponse
    {
        $this->authorize('manage', FacilitySpace::class);

        return (new SpaceResource($this->spaces->createSpace($request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(string $id): SpaceResource
    {
        $space = FacilitySpace::query()->with('building:id,code,name')->findOrFail($id);
        $this->authorize('view', $space);

        return new SpaceResource($space);
    }

    public function update(SpaceRequest $request, string $id): SpaceResource
    {
        $space = FacilitySpace::query()->findOrFail($id);
        $this->authorize('manage', $space);

        return new SpaceResource($this->spaces->updateSpace($space, Arr::except($request->validated(), ['building_id'])));
    }

    public function destroy(string $id): Response
    {
        $space = FacilitySpace::query()->findOrFail($id);
        $this->authorize('manage', $space);
        $this->spaces->deleteSpace($space);

        return response()->noContent();
    }
}
