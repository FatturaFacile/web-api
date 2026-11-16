<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A compensation planning cycle: budget pools, merit guidelines per performance rating and the merit proposals.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property int $fiscal_year
 * @property string $currency
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property Carbon $effective_on
 * @property string $status
 * @property Carbon|null $closed_at
 * @property string|null $notes
 */
final class CompCycle extends BaseEnterpriseModel
{
    public const string Draft = 'draft';

    public const string Open = 'open';

    public const string Closed = 'closed';

    protected $table = 'tl_comp_cycles';

    protected function casts(): array
    {
        return ['fiscal_year' => 'integer', 'starts_on' => 'date', 'ends_on' => 'date', 'effective_on' => 'date', 'closed_at' => 'datetime'];
    }

    /**
     * @return HasMany<CompBudgetPool, $this>
     */
    public function pools(): HasMany
    {
        return $this->hasMany(CompBudgetPool::class, 'cycle_id')->orderBy('name');
    }

    /**
     * @return HasMany<CompGuideline, $this>
     */
    public function guidelines(): HasMany
    {
        return $this->hasMany(CompGuideline::class, 'cycle_id')->orderBy('performance_rating');
    }

    /**
     * @return HasMany<CompProposal, $this>
     */
    public function proposals(): HasMany
    {
        return $this->hasMany(CompProposal::class, 'cycle_id');
    }
}
