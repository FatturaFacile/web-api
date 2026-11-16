<?php

declare(strict_types=1);

namespace Modules\Marketplace\Models;

use Database\Factories\MarketplaceHealthCheckFactory;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Support\Carbon;

/**
 * The result of one health check of an installation.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $installation_id
 * @property string $status
 * @property int|null $latency_ms
 * @property string|null $message
 * @property Carbon $checked_at
 */
#[UseFactory(MarketplaceHealthCheckFactory::class)]
final class MarketplaceHealthCheck extends BaseEnterpriseModel
{
    protected $table = 'marketplace_health_checks';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['checked_at' => 'datetime', 'latency_ms' => 'integer'];
    }
}
