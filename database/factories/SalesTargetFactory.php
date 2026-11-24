<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Invoicing\Models\SalesTarget;

/**
 * @extends Factory<SalesTarget>
 */
final class SalesTargetFactory extends Factory
{
    protected $model = SalesTarget::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'sales_representative_id' => null,
            'sales_team_id' => static fn (array $attributes): string => (string) SalesTeamFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
            'target_amount' => '850000.00',
            'currency' => 'EUR',
        ];
    }
}
