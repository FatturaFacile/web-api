<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CRM\Models\PartnerTier;

/**
 * @extends Factory<PartnerTier>
 */
final class PartnerTierFactory extends Factory
{
    protected $model = PartnerTier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => strtolower(Str::random(6)),
            'name' => fake()->word().' tier',
            'rank' => 1,
            'min_annual_revenue' => '0.00',
            'discount_percent' => '10.00',
            'mdf_percent' => '2.00',
        ];
    }
}
