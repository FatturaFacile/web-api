<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Customs\Enums\ChargeCategory;
use Modules\Customs\Enums\ChargeKind;
use Modules\Customs\Models\CustomsDossier;
use Modules\Customs\Models\CustomsDossierCharge;

/**
 * @extends Factory<CustomsDossierCharge>
 */
final class CustomsDossierChargeFactory extends Factory
{
    protected $model = CustomsDossierCharge::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'dossier_id' => static fn (array $attributes): string => (string) CustomsDossier::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'kind' => ChargeKind::Revenue,
            'category' => ChargeCategory::Freight,
            'is_direct' => true,
            'description' => 'Trasporto stradale Milano - Lugano',
            'amount' => number_format(fake()->randomFloat(2, 150, 2500), 2, '.', ''),
            'tax_rate' => '8.10',
            'currency' => 'CHF',
        ];
    }
}
