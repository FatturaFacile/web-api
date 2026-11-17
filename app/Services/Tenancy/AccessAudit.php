<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;

/**
 * Changes of who can do what in a company (members, roles, teams, delegations, invitations, imports, plan) on the audit
 * ledger, module code ACCESS. Events carry ids and roles only: the ledger is immutable, so it never holds e-mail
 * addresses or names. The action is `access.<type>.<what>` (stored in upper case), so the type of an event is its first
 * two segments.
 */
final class AccessAudit
{
    public const string Module = 'ACCESS';

    public const array Types = ['access.member', 'access.role', 'access.team', 'access.delegation', 'access.invitation', 'access.import', 'access.plan'];

    public function __construct(private readonly AuditLedgerInterface $ledger) {}

    /**
     * @param  class-string  $subjectClass
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(string $event, string $tenantId, string $subjectClass, ?string $subjectId, ?array $before = null, ?array $after = null): void
    {
        $this->ledger->recordPayload([
            'module_code' => self::Module,
            'action' => $event,
            'model_class' => $subjectClass,
            'model_id' => $subjectId,
            'tenant_id' => $tenantId,
            'company_id' => $tenantId,
            'actor_user_id' => auth()->id(),
            'payload_before' => $before === [] ? null : $before,
            'payload_after' => $after === [] ? null : $after,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
