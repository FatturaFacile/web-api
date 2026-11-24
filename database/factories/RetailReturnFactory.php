<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PosSale;
use App\Models\PosSession;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\FiscalRetail\Models\RetailReturn;

/**
 * @extends Factory<RetailReturn>
 */
final class RetailReturnFactory extends Factory
{
    protected $model = RetailReturn::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'pos_sale_id' => PosSale::factory(),
            'pos_session_id' => PosSession::factory(),
            'number' => 'RET-'.fake()->year().'-'.fake()->unique()->numerify('####'),
            'refund_method' => 'cash',
            'total_net' => '10.00',
            'total_tax' => '2.20',
            'total_gross' => '12.20',
        ];
    }
}
