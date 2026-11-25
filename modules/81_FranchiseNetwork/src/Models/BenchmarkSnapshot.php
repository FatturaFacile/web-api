<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\FranchiseNetwork\Domain\Enums\SizeCluster;

/**
 * KPI values of one store for a month, kept to compute the anonymous network statistics.
 *
 * @property string $store_id
 * @property string $period
 * @property SizeCluster $cluster
 * @property array<string, mixed> $metrics
 */
final class BenchmarkSnapshot extends BaseEnterpriseModel
{
    protected $table = 'frn_benchmark_snapshots';

    protected function casts(): array
    {
        return [
            'cluster' => SizeCluster::class,
            'metrics' => 'array',
        ];
    }

    /**
     * @return BelongsTo<FranchiseStore, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(FranchiseStore::class, 'store_id');
    }
}
