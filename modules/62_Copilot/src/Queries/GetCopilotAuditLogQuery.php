<?php

declare(strict_types=1);

namespace Modules\Copilot\Queries;

use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Copilot\Services\CopilotAuditor;

/**
 * The Copilot entries of the immutable audit ledger of one tenant, latest first.
 */
final class GetCopilotAuditLogQuery
{
    /**
     * @param  array{action?: string|null, user_id?: string|null, from?: string|null, to?: string|null}  $filters
     * @return LengthAwarePaginator<int, AuditLedgerEntry>
     */
    public function execute(string $tenantId, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return AuditLedgerEntry::query()
            ->where('tenant_id', $tenantId)
            ->where('module_code', CopilotAuditor::Module)
            ->when($filters['action'] ?? null, static fn ($query, string $action) => $query->where('action', 'COPILOT_'.strtoupper($action)))
            ->when($filters['user_id'] ?? null, static fn ($query, string $userId) => $query->where('actor_user_id', $userId))
            ->when($filters['from'] ?? null, static fn ($query, string $from) => $query->whereDate('event_timestamp', '>=', $from))
            ->when($filters['to'] ?? null, static fn ($query, string $to) => $query->whereDate('event_timestamp', '<=', $to))
            ->orderByDesc('event_timestamp')
            ->paginate(min(100, max(5, $perPage)));
    }
}
