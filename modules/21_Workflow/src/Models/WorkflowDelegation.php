<?php

declare(strict_types=1);

namespace Modules\Workflow\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Workflow\Services\ApprovableRegistry;

/**
 * An out-of-office delegation: for a period and a scope, the delegate decides what is assigned to the delegator.
 *
 * @property string $delegator_user_id
 * @property string $delegate_user_id
 * @property string $scope
 * @property bool $is_active
 * @property ?list<string> $document_types
 * @property ?string $amount_cap
 * @property Carbon $starts_at
 * @property Carbon $ends_at
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
            'document_types' => 'array',
            'amount_cap' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function delegator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegator_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
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
    public function coversApprovable(string $approvableType, ?float $amount = null): bool
    {
        // The delegator may have narrowed the delegation to some document types and to an amount ceiling.
        $types = $this->document_types;
        if (is_array($types) && $types !== []) {
            $subject = app(ApprovableRegistry::class)->resolve($approvableType);

            if (! in_array($approvableType, $types, true) && ($subject === null || ! in_array($subject, $types, true))) {
                return false;
            }
        }

        if ($this->amount_cap !== null && $amount !== null && $amount > (float) $this->amount_cap) {
            return false;
        }

        if ($this->scope === 'all' || (is_array($types) && $types !== [])) {
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
