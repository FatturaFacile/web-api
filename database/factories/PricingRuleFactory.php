<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CRM\Models\PricingRule;

/**
 * @extends Factory<PricingRule>
 */
final class PricingRuleFactory extends Factory
{
    protected $model = PricingRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'name' => fake()->words(2, true),
            'rule_type' => 'volume_discount',
            'min_quantity' => '10.000',
            'discount_percent' => '5.00',
            'priority' => 100,
            'is_active' => true,
        ];
    }
}
