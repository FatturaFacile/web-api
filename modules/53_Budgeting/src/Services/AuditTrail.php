<?php

declare(strict_types=1);

namespace Modules\Budgeting\Services;

use Modules\Budgeting\Models\BudgetAuditEvent;

/**
 * Writes the audit trail of the budget control (who did what to which version, rule or override, and why).
 */
final readonly class AuditTrail
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function record(string $tenantId, string $subjectType, string $subjectId, string $action, ?string $userId, ?string $fromStatus = null, ?string $toStatus = null, ?string $comment = null, ?array $payload = null): BudgetAuditEvent
    {
        return BudgetAuditEvent::create([
            'tenant_id' => $tenantId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'user_id' => $userId,
            'comment' => $comment,
            'payload' => $payload,
        ]);
    }
}
