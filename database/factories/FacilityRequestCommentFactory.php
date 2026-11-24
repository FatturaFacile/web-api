<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Facility\Models\FacilityRequestComment;
use Modules\Facility\Models\FacilityServiceRequest;

/**
 * @extends Factory<FacilityRequestComment>
 */
final class FacilityRequestCommentFactory extends Factory
{
    protected $model = FacilityRequestComment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'service_request_id' => static fn (array $attributes): string => (string) FacilityServiceRequest::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'body' => fake()->sentence(),
            'is_internal' => false,
        ];
    }
}
