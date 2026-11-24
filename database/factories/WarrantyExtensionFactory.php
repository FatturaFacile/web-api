<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\SerializedEquipmentPassport;
use Modules\ServiceManagement\Models\WarrantyExtension;

/**
 * @extends Factory<WarrantyExtension>
 */
class WarrantyExtensionFactory extends Factory
{
    protected $model = WarrantyExtension::class;

    public function definition(): array
    {
        $start = now()->addMonths(18)->startOfDay();

        return [
            'id' => (string) Str::uuid7(),
            'equipment_passport_id' => SerializedEquipmentPassport::factory(),
            'tenant_id' => fn (array $attributes) => SerializedEquipmentPassport::withoutGlobalScopes()->find($attributes['equipment_passport_id'])?->tenant_id,
            'name' => 'Care Pack 12M',
            'months' => 12,
            'starts_at' => $start->toDateString(),
            'ends_at' => $start->copy()->addMonthsNoOverflow(12)->toDateString(),
            'reference' => 'CP-'.strtoupper(Str::random(5)),
            'price' => 99.00,
            'currency' => 'EUR',
        ];
    }
}
