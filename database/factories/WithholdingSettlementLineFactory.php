<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\PassiveInvoice;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\WithholdingSettlementLine;

/**
 * @extends Factory<WithholdingSettlementLine>
 */
final class WithholdingSettlementLineFactory extends Factory
{
    protected $model = WithholdingSettlementLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'withholding_settlement_id' => static fn (array $attributes): string => (string) WithholdingSettlementFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'supplier_id' => static fn (array $attributes): string => (string) Contact::factory()->create(['tenant_id' => $attributes['tenant_id'], 'type' => 'supplier'])->id,
            'invoice_number' => strtoupper(fake()->unique()->bothify('PARC-2026-####')),
            'passive_invoice_id' => static fn (array $attributes): string => (string) PassiveInvoice::create([
                'tenant_id' => $attributes['tenant_id'],
                'supplier_id' => $attributes['supplier_id'],
                'invoice_number' => $attributes['invoice_number'],
                'date' => '2026-10-05',
                'due_date' => '2026-10-20',
                'total_amount' => '1220.00',
                'total_net' => '1000.00',
                'total_tax' => '220.00',
                'currency' => 'EUR',
                'document_type' => 'TD06',
            ])->id,
            'supplier_payment_id' => (string) Str::uuid(),
            'paid_at' => '2026-10-20',
            'withholding_type' => 'RT01',
            'reason' => 'A',
            'f24_code' => '1040',
            'gross_paid' => '1220.00',
            'taxable_amount' => '1000.00',
            'rate_percent' => '20.00',
            'withheld_amount' => '200.00',
            'detail_assumed' => false,
        ];
    }
}
