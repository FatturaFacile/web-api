<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\Section;
use Modules\GeneralLedger\Models\CostCenter;

/**
 * @extends Factory<Section>
 */
final class SectionFactory extends Factory
{
    protected $model = Section::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => strtoupper(fake()->unique()->bothify('SEZ-###')),
            'name' => 'Sezione '.fake()->randomElement(['Calcio', 'Pallavolo', 'Nuoto', 'Atletica']),
            'cost_center_id' => static fn (array $attributes): string => CostCenter::create([
                'tenant_id' => $attributes['tenant_id'],
                'company_id' => $attributes['tenant_id'],
                'code' => strtoupper(fake()->unique()->bothify('CDC-####')),
                'name' => 'Centro di costo sezione',
                'is_active' => true,
            ])->id,
            'is_active' => true,
        ];
    }
}
