<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CreditManagement\Models\CreditDunningStrategy;

/**
 * @extends Factory<CreditDunningStrategy>
 */
final class CreditDunningStrategyFactory extends Factory
{
    protected $model = CreditDunningStrategy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'name' => 'Strategy '.Str::random(4),
            'is_default' => false,
            'is_active' => true,
        ];
    }
}
