<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

/**
 * KPI the benchmark compares (like-for-like growth, average ticket, conversion, stock rotation, net sell-out). Global catalogue.
 *
 * @property string $code
 * @property array<string, mixed> $labels
 * @property array<string, mixed>|null $descriptions
 * @property string $unit
 * @property bool $higher_is_better
 * @property int $sort_order
 */
final class BenchmarkDimension extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'frn_benchmark_dimensions';

    protected function casts(): array
    {
        return [
            'labels' => 'array',
            'descriptions' => 'array',
            'higher_is_better' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
