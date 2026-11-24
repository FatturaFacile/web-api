<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\RegistryAssignment;
use Modules\Associations\Models\RegistryType;

/**
 * @extends Factory<RegistryAssignment>
 */
final class RegistryAssignmentFactory extends Factory
{
    protected $model = RegistryAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'member_id' => static fn (array $attributes): string => Member::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'registry_type_id' => static fn (array $attributes): string => RegistryType::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'year_id' => null,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'notes' => null,
        ];
    }
}
