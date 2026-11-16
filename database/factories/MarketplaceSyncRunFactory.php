<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Marketplace\Models\MarketplaceInstallation;
use Modules\Marketplace\Models\MarketplaceSyncRun;

/**
 * @extends Factory<MarketplaceSyncRun>
 */
final class MarketplaceSyncRunFactory extends Factory
{
    protected $model = MarketplaceSyncRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'installation_id' => MarketplaceInstallation::factory(),
            'resource' => 'charges',
            'trigger' => 'manual',
            'status' => MarketplaceSyncRun::Succeeded,
            'started_at' => now(),
            'finished_at' => now(),
            'records_processed' => 0,
            'records_failed' => 0,
        ];
    }
}
