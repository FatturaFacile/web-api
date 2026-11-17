<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\EquipmentComponent;
use Modules\ServiceManagement\Models\SerializedEquipmentPassport;

/**
 * @extends Factory<EquipmentComponent>
 */
class EquipmentComponentFactory extends Factory
{
    protected $model = EquipmentComponent::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'equipment_passport_id' => SerializedEquipmentPassport::factory(),
            'tenant_id' => fn (array $attributes) => SerializedEquipmentPassport::withoutGlobalScopes()->find($attributes['equipment_passport_id'])?->tenant_id,
            'position' => fake()->randomElement(['PSU', 'MAINBOARD', 'FAN', 'DISPLAY']),
            'component_serial' => 'C-'.strtoupper(Str::random(8)),
            'role' => 'module',
            'source' => EquipmentComponent::SOURCE_AS_BUILT,
            'installed_at' => now()->subMonths(6),
        ];
    }
}
