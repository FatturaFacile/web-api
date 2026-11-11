<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Integrations\Models\WebhookEndpoint;

/**
 * @extends Factory<WebhookEndpoint>
 */
final class WebhookEndpointFactory extends Factory
{
    protected $model = WebhookEndpoint::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $secret = WebhookEndpoint::SecretPrefix.Str::random(40);

        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->randomElement(['ERP sync', 'Zapier', 'Data warehouse']),
            'url' => 'https://hooks.example.com/'.Str::lower(Str::random(12)),
            'description' => null,
            'status' => WebhookEndpoint::StatusActive,
            'events' => ['invoicing.invoice.posted'],
            'payload_version' => 'v1',
            'secret' => $secret,
            'secret_hint' => substr($secret, -4),
            'consecutive_failures' => 0,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['status' => WebhookEndpoint::StatusDisabled, 'disabled_at' => now(), 'disabled_reason' => 'Disabled by a user.']);
    }
}
