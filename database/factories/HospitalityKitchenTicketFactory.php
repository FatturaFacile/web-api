<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Hospitality\Models\KitchenTicket;

/**
 * @extends Factory<KitchenTicket>
 */
final class HospitalityKitchenTicketFactory extends Factory
{
    protected $model = KitchenTicket::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'order_id' => static fn (array $attributes): string => (string) HospitalityOrderFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'station' => 'kitchen',
            'course' => 1,
            'status' => 'open',
            'lines' => [
                ['order_item_id' => (string) Str::uuid7(), 'description' => 'Risotto ai funghi porcini', 'quantity' => '2.0000', 'notes' => null],
                ['order_item_id' => (string) Str::uuid7(), 'description' => 'Polenta e brasato', 'quantity' => '1.0000', 'notes' => 'Senza glutine'],
            ],
            'ready_at' => null,
        ];
    }
}
