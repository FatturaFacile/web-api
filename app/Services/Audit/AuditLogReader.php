<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Domain\Audit\Enums\AuditEventType;
use App\Models\AuditLog;
use App\Models\AuditWormBatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The read side of the audit trail: filtered lists, the timeline of one record, one record with its cryptographic proof.
 * Reading a list never decrypts a payload; opening one record does, and is itself written to the trail.
 */
final class AuditLogReader
{
    public function __construct(
        private readonly AuditChainCryptoService $crypto,
        private readonly AuditTrailRecorder $recorder,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function paginate(string $tenantId, array $filters, int $perPage = 50): LengthAwarePaginator
    {
        return $this->filtered($tenantId, $filters)
            ->with('wormEntry')
            ->orderByDesc('sequence_number')
            ->paginate(max(1, min($perPage, 200)));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<AuditLog>
     */
    public function filtered(string $tenantId, array $filters): Builder
    {
        $query = AuditLog::query()->where('tenant_id', $tenantId);

        $events = $filters['event_type'] ?? null;
        $events = is_string($events) ? explode(',', $events) : $events;

        if (is_array($events) && $events !== []) {
            $query->whereIn('event_type', array_map(static fn (mixed $event): string => strtoupper((string) $event), $events));
        }

        foreach (['auditable_type', 'auditable_id', 'actor_id', 'ip_address'] as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, (string) $filters[$column]);
            }
        }

        if (! empty($filters['date_from'])) {
            $query->where('occurred_at', '>=', Carbon::parse((string) $filters['date_from'], 'UTC')->setTimezone('UTC')->format('Y-m-d H:i:s.u'));
        }

        if (! empty($filters['date_to'])) {
            $query->where('occurred_at', '<=', Carbon::parse((string) $filters['date_to'], 'UTC')->setTimezone('UTC')->format('Y-m-d H:i:s.u'));
        }

        if (! empty($filters['changed_field'])) {
            $query->whereJsonContains('changed_fields', (string) $filters['changed_field']);
        }

        return $query;
    }

    /**
     * @return Collection<int, AuditLog>
     */
    public function timeline(string $tenantId, string $type, string $id): Collection
    {
        return AuditLog::query()
            ->where('tenant_id', $tenantId)
            ->where('auditable_type', $type)
            ->where('auditable_id', $id)
            ->with('wormEntry')
            ->orderBy('sequence_number')
            ->get();
    }

    public function find(string $tenantId, string $id): ?AuditLog
    {
        return AuditLog::query()->where('tenant_id', $tenantId)->where('id', $id)->with('wormEntry')->first();
    }

    /**
     * The decrypted before/after of one record. Opening it is written to the trail as AUDIT_LOG_INSPECTED.
     *
     * @return array{old: array<string, mixed>|null, new: array<string, mixed>|null}
     */
    public function inspect(AuditLog $log): array
    {
        $values = [
            'old' => $this->crypto->decrypt($log->old_values_encrypted),
            'new' => $this->crypto->decrypt($log->new_values_encrypted),
        ];

        $this->recorder->record(
            event: AuditEventType::AuditLogInspected,
            entityType: 'AuditLog',
            entityId: $log->id,
            tenantId: $log->tenant_id,
            context: ['inspected_sequence' => $log->sequence_number, 'inspected_event' => $log->event_type],
        );

        return $values;
    }

    public function wormBatchOf(AuditLog $log): ?AuditWormBatch
    {
        $entry = $log->wormEntry;

        return $entry === null ? null : AuditWormBatch::query()->find($entry->batch_id);
    }
}
