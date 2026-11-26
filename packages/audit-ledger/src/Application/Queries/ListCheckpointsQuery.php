<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Application\Queries;

use Enterprise\AuditLedger\Models\AuditCheckpoint;
use Illuminate\Database\Eloquent\Collection;

final class ListCheckpointsQuery
{
    public const int LIMIT = 100;

    /**
     * @return Collection<int, AuditCheckpoint>
     */
    public function handle(string $tenantId, ?string $type = null): Collection
    {
        return AuditCheckpoint::query()
            ->where('tenant_id', $tenantId)
            ->when($type !== null, static fn ($query) => $query->where('type', $type))
            ->orderByDesc('sequence')
            ->orderByDesc('created_at')
            ->limit(self::LIMIT)
            ->get();
    }
}
