<?php

declare(strict_types=1);

namespace Modules\Marketplace\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One record of a sync run that could not be mapped or stored. The message never holds a credential.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $installation_id
 * @property string $sync_run_id
 * @property string $resource
 * @property string|null $external_id
 * @property string $message
 */
final class MarketplaceSyncFailure extends BaseEnterpriseModel
{
    protected $table = 'marketplace_sync_failures';

    /**
     * @return BelongsTo<MarketplaceSyncRun, $this>
     */
    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(MarketplaceSyncRun::class, 'sync_run_id');
    }
}
