<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\FranchiseNetwork\Domain\Enums\PushStatus;
use Modules\FranchiseNetwork\Domain\Enums\PushType;

/**
 * A central distribution of catalogue, price list, promotion or loyalty campaign from the franchisor to its stores.
 *
 * @property PushType $push_type
 * @property string $title
 * @property array<string, mixed> $payload
 * @property string $target_mode
 * @property list<string>|null $store_ids
 * @property PushStatus $status
 * @property Carbon|null $effective_from
 * @property Carbon|null $dispatched_at
 */
final class CatalogPush extends BaseEnterpriseModel
{
    protected $table = 'frn_catalog_pushes';

    protected function casts(): array
    {
        return [
            'push_type' => PushType::class,
            'payload' => 'array',
            'store_ids' => 'array',
            'status' => PushStatus::class,
            'effective_from' => 'date',
            'dispatched_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<CatalogPushTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(CatalogPushTarget::class, 'push_id');
    }
}
