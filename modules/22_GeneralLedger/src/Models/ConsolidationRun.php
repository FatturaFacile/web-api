<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One consolidation of a group for a fiscal period of the parent entity: an immutable snapshot of the translated
 * balances of the members and of the adjustments generated on them. `input_fingerprint` is the SHA-256 of everything
 * the figures were computed from (members, shares, rates, mappings, local balances), so a later recomputation proves
 * whether the ledgers or the rates changed since. A locked run is the official one of its period and cannot be deleted.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $group_id
 * @property string $fiscal_period_id
 * @property int $version
 * @property string $status
 * @property string $group_currency
 * @property string $input_fingerprint
 * @property array<string, mixed> $totals
 * @property Carbon $year_start
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property Carbon|null $locked_at
 * @property list<array<string, mixed>> $members_snapshot
 * @property list<array<string, mixed>> $mappings_snapshot
 * @property list<array<string, mixed>> $rates
 * @property array<string, mixed> $inputs_snapshot
 * @property list<array<string, mixed>> $warnings
 */
final class ConsolidationRun extends BaseEnterpriseModel
{
    public const string StatusCompleted = 'completed';

    public const string StatusLocked = 'locked';

    protected $table = 'gl_consolidation_runs';

    protected function casts(): array
    {
        return [
            'year_start' => 'date',
            'period_start' => 'date',
            'period_end' => 'date',
            'version' => 'integer',
            'members_snapshot' => 'array',
            'mappings_snapshot' => 'array',
            'rates' => 'array',
            'warnings' => 'array',
            'totals' => 'array',
            'inputs_snapshot' => 'array',
            'locked_at' => 'datetime',
        ];
    }

    public function isLocked(): bool
    {
        return $this->status === self::StatusLocked;
    }

    /**
     * @return BelongsTo<ConsolidationGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ConsolidationGroup::class, 'group_id');
    }

    /**
     * @return HasMany<ConsolidationRunBalance, $this>
     */
    public function balances(): HasMany
    {
        return $this->hasMany(ConsolidationRunBalance::class, 'run_id');
    }

    /**
     * @return HasMany<ConsolidationAdjustment, $this>
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(ConsolidationAdjustment::class, 'run_id')->orderBy('created_at')->orderBy('id');
    }
}
