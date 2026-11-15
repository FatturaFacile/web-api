<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Marketplace\Models\MarketplaceHealthCheck;
use Modules\Marketplace\Models\MarketplaceInstallation;

/**
 * @extends Factory<MarketplaceHealthCheck>
 */
final class MarketplaceHealthCheckFactory extends Factory
{
    protected $model = MarketplaceHealthCheck::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'installation_id' => MarketplaceInstallation::factory(),
            'status' => 'healthy',
            'latency_ms' => 120,
            'checked_at' => now(),
        ];
    }
}
