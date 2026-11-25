<?php

declare(strict_types=1);

namespace Modules\Budgeting\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * One entry of the audit trail of the budget control: version revisions and transfers, grid saves, imports, rule
 * changes and the whole life of an override.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $subject_type
 * @property string $subject_id
 * @property string $action
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string|null $user_id
 * @property string|null $comment
 * @property array<string, mixed>|null $payload
 */
final class BudgetAuditEvent extends BaseEnterpriseModel
{
    public const string SubjectVersion = 'version';

    public const string SubjectOverride = 'override';

    public const string SubjectRule = 'rule';

    public const string SubjectCommitment = 'commitment';

    protected $table = 'budget_audit_events';

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
