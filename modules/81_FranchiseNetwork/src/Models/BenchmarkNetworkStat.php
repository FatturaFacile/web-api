<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Anonymous aggregate of a KPI for a month and a size cluster (or the whole network): average, median and 75th percentile.
 *
 * @property string $period
 * @property string $cluster
 * @property string $metric
 * @property string|null $average
 * @property string|null $median
 * @property string|null $p75
 * @property int $sample_size
 */
final class BenchmarkNetworkStat extends BaseEnterpriseModel
{
    protected $table = 'frn_benchmark_network_stats';

    protected function casts(): array
    {
        return [
            'average' => 'decimal:4',
            'median' => 'decimal:4',
            'p75' => 'decimal:4',
            'sample_size' => 'integer',
        ];
    }
}
