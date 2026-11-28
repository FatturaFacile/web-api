<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Models;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Result of a what-if valuation replay: the official and the simulated valuation of the inventory at a bi-temporal coordinate.
 * It never touches the official ledger or inventory tables.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $simulation_name
 * @property string $target_valid_at
 * @property string $target_known_at
 * @property string $valuation_method
 * @property array<string, mixed> $baseline_summary
 * @property array<string, mixed> $simulated_summary
 * @property array<string, mixed> $delta_summary
 * @property list<array<string, mixed>>|null $items
 * @property string|null $created_by
 */
final class WhatIfSimulationRun extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    protected $table = 'es_whatif_simulation_runs';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'baseline_summary' => 'array',
            'simulated_summary' => 'array',
            'delta_summary' => 'array',
            'items' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
