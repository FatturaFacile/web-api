<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\FranchiseNetwork\Domain\Enums\TargetStatus;

/**
 * Delivery state of a push for one store: pending, synced, acknowledged or failed (retryable).
 *
 * @property string $push_id
 * @property string $store_id
 * @property TargetStatus $status
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $synced_at
 * @property Carbon|null $acknowledged_at
 * @property Carbon|null $next_retry_at
 */
final class CatalogPushTarget extends BaseEnterpriseModel
{
    protected $table = 'frn_catalog_push_targets';

    protected function casts(): array
    {
        return [
            'status' => TargetStatus::class,
            'attempts' => 'integer',
            'synced_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'next_retry_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CatalogPush, $this>
     */
    public function catalogPush(): BelongsTo
    {
        return $this->belongsTo(CatalogPush::class, 'push_id');
    }

    /**
     * @return BelongsTo<FranchiseStore, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(FranchiseStore::class, 'store_id');
    }
}
