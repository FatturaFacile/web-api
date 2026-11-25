<?php

declare(strict_types=1);

namespace Modules\Budgeting\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A spend control rule: how a requisition or purchase order that takes the spend of an account, cost centre or project
 * past the threshold of its budget is treated (allowed, allowed with a warning, or blocked unless an override is
 * approved). A rule without account, cost centre and project is the company default; the most specific rule wins.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $account_id
 * @property string|null $cost_center_id
 * @property string|null $project_id
 * @property string $mode
 * @property string $threshold_percent
 * @property list<string> $document_types
 * @property list<string> $stages
 * @property bool $enforce_without_budget
 * @property int $priority
 * @property bool $is_active
 */
final class BudgetSpendRule extends BaseEnterpriseModel
{
    public const string Allow = 'allow';

    public const string Warning = 'warning';

    public const string HardBlock = 'hard_block';

    public const array Modes = [self::Allow, self::Warning, self::HardBlock];

    public const array DocumentTypes = [BudgetCommitment::Requisition, BudgetCommitment::PurchaseOrder];

    public const string StageCreate = 'create';

    public const string StageSubmit = 'submit';

    public const string StageApprove = 'approve';

    public const array Stages = [self::StageCreate, self::StageSubmit, self::StageApprove];

    protected $table = 'budget_spend_rules';

    protected function casts(): array
    {
        return [
            'threshold_percent' => 'decimal:2',
            'document_types' => 'array',
            'stages' => 'array',
            'enforce_without_budget' => 'boolean',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * How many of the three dimensions the rule narrows down: the more, the more specific.
     */
    public function specificity(): int
    {
        return ($this->account_id !== null ? 1 : 0) + ($this->cost_center_id !== null ? 1 : 0) + ($this->project_id !== null ? 1 : 0);
    }
}
