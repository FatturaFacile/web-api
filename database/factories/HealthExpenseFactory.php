<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HealthExpenses\Models\HealthExpense;

/**
 * @extends Factory<HealthExpense>
 */
final class HealthExpenseFactory extends Factory
{
    protected $model = HealthExpense::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'document_type' => 'invoice',
            'document_id' => static fn (array $attributes): string => (string) SalesInvoiceFactory::new()->create([
                'tenant_id' => $attributes['tenant_id'],
                'contact_id' => Contact::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            ])->id,
            'document_kind' => 'F',
            'document_number' => static fn (array $attributes): string => (string) Invoice::query()->withoutGlobalScopes()->whereKey($attributes['document_id'])->value('invoice_number'),
            'issue_date' => '2026-10-12',
            'payment_date' => '2026-10-12',
            'is_advance_payment' => false,
            'expense_type' => 'SP',
            'expense_flag' => null,
            'amount' => '120.00',
            'tax_rate' => null,
            'vat_nature' => 'N4',
            'device_number' => '1',
            'patient_fiscal_code' => 'RSSMRA80A01F205X',
            'patient_opposition' => false,
            'payment_traceable' => true,
            'operation' => 'I',
        ];
    }
}
