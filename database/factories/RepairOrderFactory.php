<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\RepairOrder;
use Modules\Inventory\Models\StockSerialNumber;

/**
 * @extends Factory<RepairOrder>
 */
final class RepairOrderFactory extends Factory
{
    protected $model = RepairOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'repair_number' => strtoupper(fake()->unique()->bothify('RIP-2026-#####')),
            'stock_item_id' => static fn (array $attributes): string => (string) StockItemFactory::new()->create([
                'tenant_id' => $attributes['tenant_id'],
                'name' => 'Trapano avvitatore 18V',
                'tracking_type' => 'serial',
            ])->id,
            'contact_id' => static fn (array $attributes): string => (string) Contact::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'stock_serial_number_id' => static fn (array $attributes): string => (string) StockSerialNumber::create([
                'tenant_id' => $attributes['tenant_id'],
                'stock_item_id' => $attributes['stock_item_id'],
                'serial_number' => strtoupper(fake()->unique()->bothify('SN-??######')),
                'status' => 'delivered',
                'contact_id' => $attributes['contact_id'],
                'purchase_date' => '2026-03-14',
                'purchase_value' => '189.90',
                'purchase_currency' => 'EUR',
                'warranty_start_date' => '2026-03-14',
                'warranty_end_date' => '2028-03-13',
            ])->id,
            'purchase_value' => '189.90',
            'purchase_currency' => 'EUR',
            'is_under_warranty' => true,
            'status' => 'received',
            'fault_description' => 'Il motore si arresta dopo pochi secondi di utilizzo.',
            'labour_amount' => '0.00',
            'parts_amount' => '0.00',
            'received_at' => '2026-10-28 09:30:00',
        ];
    }
}
