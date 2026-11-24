<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\GeneralLedger\Models\ConsolidationGroup;

/**
 * @extends Factory<ConsolidationGroup>
 */
final class ConsolidationGroupFactory extends Factory
{
    protected $model = ConsolidationGroup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => $this->faker->unique()->words(2, true),
            'group_currency' => 'EUR',
            'is_active' => true,
        ];
    }
}
