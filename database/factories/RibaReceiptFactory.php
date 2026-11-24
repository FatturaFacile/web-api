<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Treasury\Models\RibaPresentation;
use Modules\Treasury\Models\RibaReceipt;

/**
 * @extends Factory<RibaReceipt>
 */
final class RibaReceiptFactory extends Factory
{
    protected $model = RibaReceipt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'riba_presentation_id' => static fn (array $attributes): string => (string) RibaPresentationFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'payment_batch_id' => static fn (array $attributes): string => (string) RibaPresentation::query()->withoutGlobalScopes()->whereKey($attributes['riba_presentation_id'])->value('payment_batch_id'),
            'payment_line_id' => (string) Str::uuid(),
            'invoice_id' => static fn (array $attributes): string => (string) SalesInvoiceFactory::new()->create([
                'tenant_id' => $attributes['tenant_id'],
                'contact_id' => Contact::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
                'payment_method' => 'MP12',
            ])->id,
            'payment_id' => null,
            'receipt_number' => 1,
            'due_date' => '2026-12-31',
            'amount' => '2318.00',
            'debtor_name' => fake()->company().' S.r.l.',
            'debtor_tax_code' => fake()->numerify('###########'),
            'debtor_address' => 'Via Giuseppe Mazzini 8',
            'debtor_postal_code' => '25121',
            'debtor_city' => 'Brescia',
            'debtor_abi' => '03069',
            'debtor_cab' => '11200',
            'status' => 'pending',
            'unpaid_at' => null,
            'unpaid_reason' => null,
            'unpaid_fee' => null,
        ];
    }
}
