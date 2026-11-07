<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\FiscalRetail\Models\DailyCorrispettivo;

/**
 * @extends Factory<DailyCorrispettivo>
 */
final class DailyCorrispettivoFactory extends Factory
{
    protected $model = DailyCorrispettivo::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'business_date' => '2026-11-05',
            'tax_rate' => '22.00',
            'vat_nature' => '',
            'sales_net' => '1229.51',
            'sales_tax' => '270.49',
            'sales_gross' => '1500.00',
            'voided_gross' => '0.00',
            'returned_gross' => '0.00',
            'total_net' => '1229.51',
            'total_tax' => '270.49',
            'total_gross' => '1500.00',
            'document_count' => 37,
            'gateway' => 'local',
            'gateway_status' => 'simulated',
            'gateway_reference' => 'LOCAL-2026-11-05',
            'closed_at' => '2026-11-05 20:05:00',
        ];
    }
}
