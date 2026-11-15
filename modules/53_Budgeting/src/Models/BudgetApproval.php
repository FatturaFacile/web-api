<?php

declare(strict_types=1);

namespace Modules\Budgeting\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * One step of the approval of a budget version: who submitted, approved, rejected or locked it and why.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $version_id
 * @property string $action
 * @property string $from_status
 * @property string $to_status
 * @property string|null $user_id
 * @property string|null $comment
 */
final class BudgetApproval extends BaseEnterpriseModel
{
    public const string Submit = 'submit';

    public const string Approve = 'approve';

    public const string Reject = 'reject';

    public const string Lock = 'lock';

    protected $table = 'budget_approvals';
}
