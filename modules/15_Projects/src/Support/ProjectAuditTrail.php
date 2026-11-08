<?php

declare(strict_types=1);

namespace Modules\Projects\Support;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Database\Eloquent\Model;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectAuditEntry;

/**
 * Writes the business audit trail of a project (readable per project through the API) and the tamper-evident entry of
 * the enterprise audit ledger, in the same transaction as the change they describe.
 */
final readonly class ProjectAuditTrail
{
    public function __construct(private AuditLedgerInterface $auditLedger) {}

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function record(Project $project, string $actorUserId, string $eventType, string $description, array $before = [], array $after = [], ?Model $subject = null): void
    {
        ProjectAuditEntry::create([
            'tenant_id' => $project->tenant_id,
            'company_id' => $project->company_id,
            'project_id' => $project->id,
            'actor_user_id' => $actorUserId,
            'event_type' => $eventType,
            'description' => $description,
            'before_state' => $before,
            'after_state' => $after,
        ]);

        $this->auditLedger->record(
            action: $eventType,
            model: $subject ?? $project,
            payloadBefore: $before === [] ? null : $before,
            payloadAfter: $after,
            moduleCode: 'MODULE_15_PROJECTS'
        );
    }
}
