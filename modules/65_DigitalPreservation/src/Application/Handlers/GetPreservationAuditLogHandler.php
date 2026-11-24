<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Application\Handlers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\DigitalPreservation\Application\Queries\GetPreservationAuditLogQuery;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationAuditLog;

final readonly class GetPreservationAuditLogHandler
{
    /**
     * @return LengthAwarePaginator<int, PreservationAuditLog>
     */
    public function handle(GetPreservationAuditLogQuery $query): LengthAwarePaginator
    {
        return PreservationAuditLog::query()
            ->when($query->action, fn ($q, $v) => $q->where('action', $v))
            ->when($query->batchId, fn ($q, $v) => $q->where('preservation_batch_id', $v))
            ->orderByDesc('sequence')
            ->paginate(max(1, min($query->perPage, 200)));
    }
}
