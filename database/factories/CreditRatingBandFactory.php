<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CreditManagement\Models\CreditRatingBand;

/**
 * @extends Factory<CreditRatingBand>
 */
final class CreditRatingBandFactory extends Factory
{
    protected $model = CreditRatingBand::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'B',
            'name' => 'Good',
            'min_score' => '50.00',
            'max_score' => '100.00',
            'risk_level' => 'low',
            'limit_multiplier' => '1.00',
            'limit_ceiling' => '50000.00',
        ];
    }
}
