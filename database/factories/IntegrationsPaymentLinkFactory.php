<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Integrations\Models\PaymentLink;

/**
 * @extends Factory<PaymentLink>
 */
final class IntegrationsPaymentLinkFactory extends Factory
{
    protected $model = PaymentLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $reference = 'plink_'.Str::lower(Str::random(20));

        return [
            'tenant_id' => Tenant::factory(),
            'invoice_id' => static fn (array $attributes): string => (string) SalesInvoiceFactory::new()->create([
                'tenant_id' => $attributes['tenant_id'],
                'contact_id' => Contact::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            ])->id,
            'provider' => 'local',
            'provider_reference' => $reference,
            'url' => 'https://pay.example.test/'.$reference,
            'amount' => '2318.00',
            'currency' => 'EUR',
            'status' => 'open',
            'payment_id' => null,
            'paid_at' => null,
            'review_reason' => null,
        ];
    }
}
