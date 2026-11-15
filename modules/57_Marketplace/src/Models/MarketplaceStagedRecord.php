<?php

declare(strict_types=1);

namespace Modules\Marketplace\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A record pulled from an external system and mapped to the shape of the application, waiting to be turned into a
 * domain record. Unique by idempotency key per installation, so a re-sync updates it instead of duplicating it.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $installation_id
 * @property string|null $sync_run_id
 * @property string $connector_key
 * @property string $resource
 * @property string $entity_type
 * @property string $external_id
 * @property string $idempotency_key
 * @property string $content_hash
 * @property array<string, mixed> $payload
 * @property string $status
 * @property Carbon|null $processed_at
 */
final class MarketplaceStagedRecord extends BaseEnterpriseModel
{
    public const string Pending = 'pending';

    public const string Processed = 'processed';

    protected $table = 'marketplace_staged_records';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<MarketplaceInstallation, $this>
     */
    public function installation(): BelongsTo
    {
        return $this->belongsTo(MarketplaceInstallation::class, 'installation_id');
    }
}
