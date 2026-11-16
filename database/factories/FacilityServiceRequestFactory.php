<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Facility\Models\FacilityServiceRequest;

/**
 * @extends Factory<FacilityServiceRequest>
 */
final class FacilityServiceRequestFactory extends Factory
{
    protected $model = FacilityServiceRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'number' => 'SR-'.now()->format('Y').'-'.fake()->unique()->numerify('####'),
            'category' => fake()->randomElement(FacilityServiceRequest::Categories),
            'priority' => 'normal',
            'status' => 'new',
            'title' => fake()->sentence(4),
            'description' => fake()->sentence(),
            'due_at' => now()->addDay(),
        ];
    }
}
