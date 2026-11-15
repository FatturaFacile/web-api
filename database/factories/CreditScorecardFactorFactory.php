<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CreditManagement\Models\CreditScorecardFactor;

/**
 * @extends Factory<CreditScorecardFactor>
 */
final class CreditScorecardFactorFactory extends Factory
{
    protected $model = CreditScorecardFactor::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'factor_'.Str::lower(Str::random(6)),
            'name' => 'Scorecard factor',
            'source' => 'manual',
            'weight' => '10.00',
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
