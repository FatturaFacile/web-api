<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PosSale;
use App\Models\PosSession;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Hospitality\Models\OrderPayment;

/**
 * @extends Factory<OrderPayment>
 */
final class HospitalityOrderPaymentFactory extends Factory
{
    protected $model = OrderPayment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $net = fake()->randomFloat(2, 30, 400);
        $tax = round($net * 0.081, 2);
        $gross = number_format($net + $tax, 2, '.', '');

        return [
            'tenant_id' => Tenant::factory(),
            'order_id' => static fn (array $attributes): string => (string) HospitalityOrderFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'pos_sale_id' => static function (array $attributes) use ($net, $tax, $gross): string {
                $tenantId = $attributes['tenant_id'];
                $terminal = PosTerminal::query()->create([
                    'tenant_id' => $tenantId,
                    'terminal_code' => strtoupper(fake()->unique()->bothify('CASSA-####')),
                    'name' => 'Cassa sala',
                    'is_active' => true,
                ]);
                $session = PosSession::query()->create([
                    'tenant_id' => $tenantId,
                    'pos_terminal_id' => $terminal->id,
                    'user_id' => User::factory()->create()->id,
                    'opened_at' => now()->setTime(11, 0),
                    'opening_float' => '200.00',
                    'status' => 'open',
                ]);

                return (string) PosSale::query()->create([
                    'tenant_id' => $tenantId,
                    'pos_session_id' => $session->id,
                    'receipt_number' => strtoupper(fake()->unique()->bothify('SC-2026-######')),
                    'total_net' => number_format($net, 2, '.', ''),
                    'total_tax' => number_format($tax, 2, '.', ''),
                    'total_gross' => $gross,
                    'payment_method' => 'card',
                    'card_amount' => $gross,
                    'status' => 'completed',
                ])->id;
            },
            'mode' => 'equal',
            'amount' => $gross,
            'lines' => [],
        ];
    }
}
