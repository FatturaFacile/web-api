<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Documents\Models\DmsRetentionPolicy;

/**
 * @extends Factory<DmsRetentionPolicy>
 */
final class DmsRetentionPolicyFactory extends Factory
{
    protected $model = DmsRetentionPolicy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => $this->faker->words(2, true),
            'retention_months' => 12,
            'action' => 'archive',
            'is_active' => true,
        ];
    }
}
