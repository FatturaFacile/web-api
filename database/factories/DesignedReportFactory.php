<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Analytics\Models\DesignedReport;

/**
 * @extends Factory<DesignedReport>
 */
final class DesignedReportFactory extends Factory
{
    protected $model = DesignedReport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'name' => fake()->unique()->words(3, true),
            'description' => null,
            'dataset' => 'sales',
            'spec' => ['dimensions' => ['customer'], 'measures' => ['net_amount']],
            'is_shared' => false,
            'shared_with' => [],
        ];
    }

    /**
     * Visible to every analytics reader of the company.
     */
    public function shared(): static
    {
        return $this->state(['is_shared' => true]);
    }
}
