<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Facility\Models\FacilityBuilding;
use Modules\Facility\Models\FacilitySpace;

/**
 * @extends Factory<FacilitySpace>
 */
final class FacilitySpaceFactory extends Factory
{
    protected $model = FacilitySpace::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'building_id' => static fn (array $attributes): string => (string) FacilityBuilding::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'parent_id' => null,
            'kind' => 'office',
            'code' => 'S'.fake()->unique()->numerify('####'),
            'name' => fake()->words(2, true),
            'capacity' => 8,
            'is_bookable' => false,
            'is_active' => true,
        ];
    }

    public function floor(): self
    {
        return $this->state(fn (): array => ['kind' => 'floor', 'capacity' => null, 'floor_level' => 1]);
    }

    public function meetingRoom(int $capacity = 8): self
    {
        return $this->state(fn (): array => ['kind' => 'meeting_room', 'capacity' => $capacity, 'is_bookable' => true]);
    }

    public function desk(): self
    {
        return $this->state(fn (): array => ['kind' => 'desk', 'capacity' => 1, 'is_bookable' => true]);
    }

    public function parking(): self
    {
        return $this->state(fn (): array => ['kind' => 'parking', 'capacity' => 1, 'is_bookable' => true]);
    }
}
