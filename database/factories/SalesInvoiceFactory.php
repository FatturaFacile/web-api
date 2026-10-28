<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Invoicing\Models\SalesInvoice;

/**
 * @extends Factory<SalesInvoice>
 */
final class SalesInvoiceFactory extends Factory
{
    protected $model = SalesInvoice::class;

    public function definition(): array
    {
        $tenantId = (string) Str::uuid7();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'contact_id' => Contact::factory(),
            'invoice_type' => 'standard',
            'status' => 'draft',
            'invoice_number' => 'INV-2027-'.$this->faker->numerify('#####'),
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency' => 'EUR',
            'exchange_rate' => 1.0,
            'subtotal' => 2000.00,
            'discount_amount' => 100.00,
            'total_net' => 1900.00,
            'total_tax' => 418.00,
            'total_gross' => 2318.00,
            'receivable_amount' => 2318.00,
            'total_paid' => 0.00,
            'payment_status' => 'unpaid',
            'jurisdiction' => 'IT',
            'split_payment' => false,
            'reverse_charge' => false,
            'payment_method' => 'MP05',
            'payment_terms' => 'TP02',
            'notes' => 'Factory sales invoice notes',
        ];
    }

    public function posted(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by' => (string) Str::uuid7(),
        ]);
    }

    public function creditNote(): self
    {
        return $this->state(fn (array $attributes) => [
            'invoice_type' => 'credit_note',
            'status' => 'posted',
            'invoice_number' => 'CN-2027-'.$this->faker->numerify('#####'),
            'total_paid' => 2318.00,
            'payment_status' => 'paid',
            'receivable_amount' => 0.00,
        ]);
    }
}
