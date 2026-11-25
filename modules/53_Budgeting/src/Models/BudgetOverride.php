<?php

declare(strict_types=1);

namespace Modules\Budgeting\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A request to spend beyond the budget (deroga) decided by the CFO or another authorised approver. A document override
 * covers one requisition or purchase order; an allowance is a temporary authorisation for an account, cost centre or
 * project up to an amount until a date. The approved amount may be lower than the requested one.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $scope
 * @property string|null $source_type
 * @property string|null $source_id
 * @property string|null $source_number
 * @property string|null $account_id
 * @property string|null $cost_center_id
 * @property string|null $project_id
 * @property int $fiscal_year
 * @property string $requested_amount
 * @property string $shortfall_amount
 * @property string|null $approved_amount
 * @property string $consumed_amount
 * @property string $reason
 * @property string $status
 * @property string|null $requested_by
 * @property string|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_comment
 * @property Carbon|null $valid_until
 */
final class BudgetOverride extends BaseEnterpriseModel
{
    public const string ScopeDocument = 'document';

    public const string ScopeAllowance = 'allowance';

    public const array Scopes = [self::ScopeDocument, self::ScopeAllowance];

    public const string Pending = 'pending';

    public const string Approved = 'approved';

    public const string Rejected = 'rejected';

    public const string Cancelled = 'cancelled';

    public const string Consumed = 'consumed';

    public const array Statuses = [self::Pending, self::Approved, self::Rejected, self::Cancelled, self::Consumed];

    protected $table = 'budget_overrides';

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'requested_amount' => 'decimal:2',
            'shortfall_amount' => 'decimal:2',
            'approved_amount' => 'decimal:2',
            'consumed_amount' => 'decimal:2',
            'decided_at' => 'datetime',
            'valid_until' => 'date',
        ];
    }

    public function isDocument(): bool
    {
        return $this->scope === self::ScopeDocument;
    }

    public function isExpired(?Carbon $today = null): bool
    {
        return $this->valid_until !== null && $this->valid_until->lt(($today ?? now())->startOfDay());
    }

    /**
     * What the approved override still covers.
     */
    public function remaining(): string
    {
        $remaining = bcsub((string) ($this->approved_amount ?? '0'), (string) $this->consumed_amount, 2);

        return bccomp($remaining, '0', 2) < 0 ? '0.00' : $remaining;
    }
}
