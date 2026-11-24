<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Marketplace\Models\MarketplaceInstallation;

/**
 * @extends Factory<MarketplaceInstallation>
 */
final class MarketplaceInstallationFactory extends Factory
{
    protected $model = MarketplaceInstallation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'connector_key' => 'stripe',
            'connector_version' => '1.0.0',
            'name' => 'Stripe '.fake()->unique()->numberBetween(1, 99999),
            'status' => MarketplaceInstallation::PendingConfiguration,
            'granted_scopes' => ['payment_links.write', 'treasury.write', 'invoicing.read'],
        ];
    }

    /**
     * A configured installation of Stripe with a random credential.
     */
    public function configured(): self
    {
        return $this->state(fn (): array => [
            'status' => MarketplaceInstallation::Disabled,
            'settings' => [],
            'credentials' => ['secret_key' => Str::random(40)],
            'configured_at' => now(),
        ]);
    }

    public function enabled(): self
    {
        return $this->configured()->state(fn (): array => ['status' => MarketplaceInstallation::Enabled, 'enabled_at' => now()]);
    }
}
