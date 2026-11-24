<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\PassiveInvoice;
use App\Models\PassiveInvoiceLine;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PassiveInvoiceLine>
 */
final class PassiveInvoiceLineFactory extends Factory
{
    protected $model = PassiveInvoiceLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'passive_invoice_id' => static function (array $attributes): string {
                $supplier = Contact::factory()->create(['tenant_id' => $attributes['tenant_id'], 'type' => 'supplier']);

                return (string) PassiveInvoice::create([
                    'tenant_id' => $attributes['tenant_id'],
                    'supplier_id' => $supplier->id,
                    'invoice_number' => strtoupper(fake()->unique()->bothify('FPR-2026-#####')),
                    'date' => '2026-10-15',
                    'due_date' => '2026-11-14',
                    'total_amount' => '305.00',
                    'total_net' => '250.00',
                    'total_tax' => '55.00',
                    'currency' => 'EUR',
                    'document_type' => 'TD01',
                ])->id;
            },
            'line_number' => 1,
            'description' => 'Consulenza tecnica - '.fake()->words(3, true),
            'quantity' => '2.0000',
            'unit_of_measure' => 'H',
            'unit_price' => '125.00000000',
            'line_total' => '250.00',
            'tax_rate' => '22.00',
            'vat_nature' => null,
            'cost_account_id' => null,
            'cost_account_source' => null,
        ];
    }
}
