<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Transportation\Models\TmsCarrier;
use Modules\Transportation\Models\TmsFreightInvoice;

/**
 * @extends Factory<TmsFreightInvoice>
 */
final class TmsFreightInvoiceFactory extends Factory
{
    protected $model = TmsFreightInvoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'carrier_id' => TmsCarrier::factory(),
            'invoice_number' => fake()->unique()->bothify('INV-#####'),
            'invoice_date' => '2027-01-31',
            'currency' => 'EUR',
            'total_amount' => '0',
            'expected_total' => '0',
            'variance_amount' => '0',
            'tolerance_percent' => '2',
            'audit_status' => 'pending',
            'dispute_status' => 'none',
        ];
    }
}
