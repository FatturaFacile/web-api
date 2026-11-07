<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Childcare\Models\ActivityEnrolment;

/**
 * @extends Factory<ActivityEnrolment>
 */
final class ChildcareActivityEnrolmentFactory extends Factory
{
    protected $model = ActivityEnrolment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'activity_id' => static fn (array $attributes): string => (string) ChildcareActivityFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'child_id' => static fn (array $attributes): string => (string) ChildcareChildFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'valid_from' => '2026-09-01',
            'valid_to' => '2027-06-30',
        ];
    }
}
