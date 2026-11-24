<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesOrder;

/**
 * @extends Factory<SalesDeliveryNote>
 */
final class SalesDeliveryNoteFactory extends Factory
{
    protected $model = SalesDeliveryNote::class;

    public function definition(): array
    {
        $tenantId = (string) Str::uuid7();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'contact_id' => Contact::factory(),
            'sales_order_id' => SalesOrder::factory(),
            'ddt_number' => 'DDT-2027-'.$this->faker->numerify('#####'),
            'date' => now()->toDateString(),
            'transport_reason' => 'Vendita',
            'goods_appearance' => 'A vista',
            'packages_count' => 2,
            'gross_weight_kg' => 25.50,
            'carrier_name' => 'DHL Express',
            'status' => 'draft',
            'shipping_status' => 'draft',
            'notes' => 'Factory delivery note notes',
        ];
    }

    public function dispatched(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'issued',
            'shipping_status' => 'dispatched',
            'shipped_at' => now(),
            'tracking_number' => 'TRK-'.$this->faker->numerify('##########'),
        ]);
    }
}
