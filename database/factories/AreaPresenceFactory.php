<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\AccessArea;
use Modules\Associations\Models\AreaPresence;
use Modules\Associations\Models\Member;

/**
 * @extends Factory<AreaPresence>
 */
final class AreaPresenceFactory extends Factory
{
    protected $model = AreaPresence::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'area_id' => static fn (array $attributes): string => AccessArea::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'member_id' => static fn (array $attributes): string => Member::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'entered_at' => now()->subHours(2),
            'left_at' => null,
        ];
    }

    public function left(): static
    {
        return $this->state(['left_at' => now()->subMinutes(15)]);
    }
}
