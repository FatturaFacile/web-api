<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A personalised work cycle: the ordered steps of a job (prime, fill, sand, two coats) priced per unit of the cycle.
 *
 * @property string $id
 * @property string $name
 * @property string $unit
 * @property array<string, string> $descriptions
 */
final class WorkCycle extends TradesRecord
{
    protected $table = 'ct_work_cycles';

    protected function casts(): array
    {
        return ['descriptions' => 'array', 'is_active' => 'boolean'];
    }

    /**
     * @return HasMany<WorkCycleStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(WorkCycleStep::class, 'work_cycle_id')->orderBy('sort_order');
    }
}
