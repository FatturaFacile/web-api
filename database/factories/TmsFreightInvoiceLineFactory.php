<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Transportation\Models\TmsFreightInvoice;
use Modules\Transportation\Models\TmsFreightInvoiceLine;

/**
 * @extends Factory<TmsFreightInvoiceLine>
 */
final class TmsFreightInvoiceLineFactory extends Factory
{
    protected $model = TmsFreightInvoiceLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'freight_invoice_id' => TmsFreightInvoice::factory(),
            'billed_amount' => '100.00',
            'audit_status' => 'pending',
        ];
    }
}
