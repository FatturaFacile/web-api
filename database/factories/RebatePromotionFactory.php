<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Rebates\Models\RebatePromotion;

/**
 * @extends Factory<RebatePromotion>
 */
final class RebatePromotionFactory extends Factory
{
    protected $model = RebatePromotion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'PR-'.Str::upper(Str::random(6)),
            'name' => fake()->words(3, true),
            'discount_type' => RebatePromotion::Percentage,
            'discount_value' => '10.0000',
            'starts_on' => '2027-06-01',
            'ends_on' => '2027-06-30',
            'is_active' => true,
        ];
    }
}
