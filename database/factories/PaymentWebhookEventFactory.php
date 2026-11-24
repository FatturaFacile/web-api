<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Integrations\Models\PaymentWebhookEvent;

/**
 * @extends Factory<PaymentWebhookEvent>
 */
final class PaymentWebhookEventFactory extends Factory
{
    protected $model = PaymentWebhookEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'provider' => 'stripe',
            'event_id' => 'evt_'.Str::random(24),
        ];
    }
}
