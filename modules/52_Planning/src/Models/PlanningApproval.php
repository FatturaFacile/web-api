<?php

declare(strict_types=1);

namespace Modules\Planning\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * One step of the S&OP cycle of a scenario: who submitted, approved, rejected, locked or published it and why.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $scenario_id
 * @property string $action
 * @property string $from_status
 * @property string $to_status
 * @property string|null $user_id
 * @property string|null $comment
 */
final class PlanningApproval extends BaseEnterpriseModel
{
    public const string Submit = 'submit';

    public const string Approve = 'approve';

    public const string Reject = 'reject';

    public const string Lock = 'lock';

    public const string Publish = 'publish';

    protected $table = 'planning_approvals';
}
