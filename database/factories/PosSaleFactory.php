<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PosSale;
use App\Models\PosSession;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosSale>
 */
final class PosSaleFactory extends Factory
{
    protected $model = PosSale::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'pos_session_id' => fn (array $attributes): string => (string) PosSession::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'receipt_number' => strtoupper(fake()->unique()->bothify('SC-2027-######')),
            'total_net' => '10.00',
            'total_tax' => '2.20',
            'total_gross' => '12.20',
            'payment_method' => 'cash',
            'cash_amount' => '12.20',
            'card_amount' => '0.00',
            'status' => 'completed',
        ];
    }
}
