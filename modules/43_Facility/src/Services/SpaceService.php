<?php

declare(strict_types=1);

namespace Modules\Facility\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Facility\Models\FacilityBooking;
use Modules\Facility\Models\FacilityBuilding;
use Modules\Facility\Models\FacilitySpace;

/**
 * The register of buildings, floors and spaces. A floor sits under the building; a desk, a parking spot or a meeting
 * room is a leaf; no space is its own ancestor and every child lives in the building of its parent.
 */
final readonly class SpaceService
{
    public function __construct(private FacilityLedger $ledger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createBuilding(array $data): FacilityBuilding
    {
        return FacilityBuilding::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateBuilding(FacilityBuilding $building, array $data): FacilityBuilding
    {
        $building->update($data);

        return $building->refresh();
    }

    public function deleteBuilding(FacilityBuilding $building): void
    {
        if ($building->spaces()->exists()) {
            throw ValidationException::withMessages(['building' => [__('facility_errors.building.has_spaces')]]);
        }

        $building->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createSpace(array $data): FacilitySpace
    {
        $this->assertPlacement((string) $data['building_id'], (string) $data['kind'], $data['parent_id'] ?? null, null);
        $this->assertBookable((string) $data['kind'], (bool) ($data['is_bookable'] ?? false), $data['capacity'] ?? null);

        return FacilitySpace::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateSpace(FacilitySpace $space, array $data): FacilitySpace
    {
        $kind = (string) ($data['kind'] ?? $space->kind);
        $parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $space->parent_id;

        if ($kind !== $space->kind && in_array($kind, [...FacilitySpace::LeafKinds, FacilitySpace::Floor], true) && $space->children()->exists()) {
            throw ValidationException::withMessages(['kind' => [__('facility_errors.space.kind_has_children')]]);
        }

        $this->assertPlacement((string) $space->building_id, $kind, $parentId, $space);
        $this->assertBookable($kind, (bool) ($data['is_bookable'] ?? $space->is_bookable), array_key_exists('capacity', $data) ? $data['capacity'] : $space->capacity);

        $space->update($data);

        return $space->refresh();
    }

    public function deleteSpace(FacilitySpace $space): void
    {
        if ($space->children()->exists()) {
            throw ValidationException::withMessages(['space' => [__('facility_errors.space.has_children')]]);
        }

        $hasFutureBookings = FacilityBooking::query()
            ->where('space_id', $space->id)
            ->whereIn('status', FacilityBooking::OccupyingStatuses)
            ->where('ends_at', '>', now())
            ->exists();

        if ($hasFutureBookings) {
            throw ValidationException::withMessages(['space' => [__('facility_errors.space.has_future_bookings')]]);
        }

        DB::transaction(function () use ($space): void {
            $space->delete();
            $this->ledger->record('FACILITY_SPACE_DELETED', $space, ['kind' => $space->kind]);
        });
    }

    /**
     * The spaces of a building as a nested tree: floors first, then every space under its parent.
     *
     * @return list<array{space: FacilitySpace, children: list<array<string, mixed>>}>
     */
    public function tree(FacilityBuilding $building): array
    {
        $spaces = FacilitySpace::query()->where('building_id', $building->id)->orderBy('floor_level')->orderBy('code')->get();
        $byParent = $spaces->groupBy(static fn (FacilitySpace $space): string => (string) ($space->parent_id ?? ''));

        $build = function (string $parentKey) use (&$build, $byParent): array {
            return $byParent->get($parentKey, collect())
                ->map(static fn (FacilitySpace $space): array => ['space' => $space, 'children' => $build((string) $space->id)])
                ->values()
                ->all();
        };

        return $build('');
    }

    private function assertPlacement(string $buildingId, string $kind, ?string $parentId, ?FacilitySpace $self): void
    {
        if ($kind === FacilitySpace::Floor && $parentId !== null) {
            throw ValidationException::withMessages(['parent_id' => [__('facility_errors.space.floor_has_no_parent')]]);
        }

        if ($parentId === null) {
            return;
        }

        $parent = FacilitySpace::query()->find($parentId);

        if ($parent === null) {
            throw ValidationException::withMessages(['parent_id' => [__('facility_errors.space.parent_not_found')]]);
        }

        if ((string) $parent->building_id !== $buildingId) {
            throw ValidationException::withMessages(['parent_id' => [__('facility_errors.space.parent_other_building')]]);
        }

        if (in_array($parent->kind, FacilitySpace::LeafKinds, true)) {
            throw ValidationException::withMessages(['parent_id' => [__('facility_errors.space.parent_is_leaf', ['kind' => $parent->kind])]]);
        }

        for ($ancestor = $parent, $depth = 0; $ancestor !== null && $depth < 50; $ancestor = $ancestor->parent, $depth++) {
            if ($self !== null && (string) $ancestor->id === (string) $self->id) {
                throw ValidationException::withMessages(['parent_id' => [__('facility_errors.space.cycle')]]);
            }
        }
    }

    private function assertBookable(string $kind, bool $isBookable, mixed $capacity): void
    {
        if (! $isBookable) {
            return;
        }

        if (! in_array($kind, FacilitySpace::BookableKinds, true)) {
            throw ValidationException::withMessages(['is_bookable' => [__('facility_errors.space.kind_not_bookable', ['kind' => $kind])]]);
        }

        if ($capacity === null || (int) $capacity < 1) {
            throw ValidationException::withMessages(['capacity' => [__('facility_errors.space.capacity_required')]]);
        }
    }
}
