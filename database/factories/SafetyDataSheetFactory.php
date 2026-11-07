<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\SafetyDataSheet;

/**
 * @extends Factory<SafetyDataSheet>
 */
final class SafetyDataSheetFactory extends Factory
{
    protected $model = SafetyDataSheet::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'product_id' => static fn (array $attributes): string => (string) Product::create([
                'tenant_id' => $attributes['tenant_id'],
                'sku' => strtoupper(fake()->unique()->bothify('CHM-#####')),
                'name' => 'Sgrassatore industriale 5 L',
                'unit_measure' => 'PZ',
                'cost_price' => '9.2000',
                'sale_price' => '18.5000',
                'tax_rate' => '22.00',
            ])->id,
            'language' => 'it',
            'title' => 'Scheda dati di sicurezza - Sgrassatore industriale',
            'current_version_id' => null,
        ];
    }
}
