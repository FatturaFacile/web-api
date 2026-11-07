<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\BookableResource;
use Modules\Associations\Models\ResourceBooking;

/**
 * @extends Factory<ResourceBooking>
 */
final class ResourceBookingFactory extends Factory
{
    protected $model = ResourceBooking::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'resource_id' => static fn (array $attributes): string => BookableResource::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'member_id' => null,
            'course_id' => null,
            'title' => 'Allenamento under 14',
            'starts_at' => '2026-11-16 18:00:00',
            'ends_at' => '2026-11-16 19:30:00',
            'status' => ResourceBooking::Confirmed,
        ];
    }
}
