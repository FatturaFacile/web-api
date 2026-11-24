<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Treasury\Models\OpenBankingConsent;
use Modules\Treasury\Models\OpenBankingPayment;

/**
 * @extends Factory<OpenBankingPayment>
 */
final class OpenBankingPaymentFactory extends Factory
{
    protected $model = OpenBankingPayment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'consent_id' => OpenBankingConsent::factory(),
            'tenant_id' => fn (array $attributes): string => (string) OpenBankingConsent::withoutGlobalScopes()->findOrFail($attributes['consent_id'])->tenant_id,
            'debtor_iban' => 'CH9300762011623852957',
            'creditor_iban' => 'DE89370400440532013000',
            'creditor_name' => 'Supplier',
            'amount' => '10.00',
            'currency' => 'EUR',
            'external_payment_id' => 'LOCAL-'.(string) Str::uuid7(),
            'status' => 'simulated',
        ];
    }
}
