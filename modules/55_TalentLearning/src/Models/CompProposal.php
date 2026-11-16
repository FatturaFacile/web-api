<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A merit increase proposed for an employee. The salary is the one of human resources at the time of the proposal.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $cycle_id
 * @property string $budget_pool_id
 * @property string $employee_id
 * @property int $performance_rating
 * @property string $current_salary
 * @property string $increase_percent
 * @property string $increase_amount
 * @property string $new_salary
 * @property bool $is_exception
 * @property string|null $justification
 * @property string $status
 * @property Carbon|null $submitted_at
 * @property string|null $decided_by_user_id
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 */
final class CompProposal extends BaseEnterpriseModel
{
    public const string Draft = 'draft';

    public const string Submitted = 'submitted';

    public const string Approved = 'approved';

    public const string Rejected = 'rejected';

    protected $table = 'tl_comp_proposals';

    protected function casts(): array
    {
        return [
            'performance_rating' => 'integer',
            'current_salary' => 'decimal:2',
            'increase_percent' => 'decimal:2',
            'increase_amount' => 'decimal:2',
            'new_salary' => 'decimal:2',
            'is_exception' => 'boolean',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CompCycle, $this>
     */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(CompCycle::class, 'cycle_id');
    }

    /**
     * @return BelongsTo<CompBudgetPool, $this>
     */
    public function pool(): BelongsTo
    {
        return $this->belongsTo(CompBudgetPool::class, 'budget_pool_id');
    }
}
