<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\SerializedEquipmentPassport;

/**
 * @extends Factory<SerializedEquipmentPassport>
 */
class SerializedEquipmentPassportFactory extends Factory
{
    protected $model = SerializedEquipmentPassport::class;

    public function definition(): array
    {
        $delivery = now()->subMonths(6)->startOfDay();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'serial_number' => 'SN-'.strtoupper(Str::random(10)),
            'current_owner_customer_id' => fn (array $attributes) => Contact::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'customer_type' => 'b2c',
            'jurisdiction' => 'IT',
            'delivery_date' => $delivery->toDateString(),
            'warranty_policy_type' => 'statutory_it_b2c_24m',
            'warranty_start_date' => $delivery->toDateString(),
            'warranty_expiry_date' => $delivery->copy()->addMonthsNoOverflow(24)->toDateString(),
            'warranty_source' => 'statutory',
        ];
    }

    public function swiss(): static
    {
        return $this->state(fn () => ['jurisdiction' => 'CH', 'warranty_policy_type' => 'statutory_ch_24m']);
    }

    public function outOfWarranty(): static
    {
        return $this->state(function () {
            $delivery = now()->subYears(3)->startOfDay();

            return [
                'delivery_date' => $delivery->toDateString(),
                'warranty_start_date' => $delivery->toDateString(),
                'warranty_expiry_date' => $delivery->copy()->addMonthsNoOverflow(24)->toDateString(),
            ];
        });
    }
}
