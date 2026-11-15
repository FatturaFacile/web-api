<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PosSession;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\FiscalRetail\Models\RetailCashMovement;

/**
 * @extends Factory<RetailCashMovement>
 */
final class RetailCashMovementFactory extends Factory
{
    protected $model = RetailCashMovement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'pos_session_id' => PosSession::factory(),
            'type' => 'pay_in',
            'amount' => '50.00',
            'reason' => fake()->sentence(3),
        ];
    }
}
