<?php

declare(strict_types=1);

namespace Modules\Workflow\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An out-of-office delegation: for a period and a scope, the delegate decides what is assigned to the delegator.
 *
 * @property string $delegator_user_id
 * @property string $delegate_user_id
 * @property string $scope
 * @property bool $is_active
 */
final class WorkflowDelegation extends BaseEnterpriseModel
{
    protected $table = 'workflow_delegations';

    /**
     * Calculated columns of the grid (portable SQL, see App\Support\Http\GridColumns).
     *
     * `state` is the readable state of the boolean `is_active` flag (`active` / `expired`), the same on every database.
     *
     * @return array<string, string>
     */
    public function gridColumns(): array
    {
        return [
            'state' => "CASE WHEN workflow_delegations.is_active THEN 'active' ELSE 'expired' END",
        ];
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function delegator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegator_user_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_user_id');
    }

    /**
     * Whether the delegation applies to an approval of this kind of record. `all` applies to everything; any other scope
     * names a module (`procurement`, `invoicing`, `hr`...) and covers only the records the workflow configuration maps to
     * that module. A scope with no module (`custom`, `expenses`) covers no approval at all: delegating is never wider
     * than what the delegator chose.
     */
    public function coversApprovable(string $approvableType): bool
    {
        if ($this->scope === 'all') {
            return true;
        }

        foreach ((array) config('workflow.approvables', []) as $definition) {
            if (($definition['class'] ?? null) === $approvableType) {
                return ($definition['module'] ?? null) === $this->scope;
            }
        }

        return false;
    }

    public function isEffectiveNow(): bool
    {
        $now = now();

        return $this->is_active && $now->between($this->starts_at, $this->ends_at);
    }
}
