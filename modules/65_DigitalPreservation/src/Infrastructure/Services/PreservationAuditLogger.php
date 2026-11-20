<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Infrastructure\Services;

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\DigitalPreservation\Domain\Enums\AuditAction;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationAuditLog;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatch;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatchItem;

/**
 * Appends entries to the tamper-evident audit log: each entry carries the hash of the previous one of the tenant.
 */
final readonly class PreservationAuditLogger
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * @param  array<string, mixed>  $details  scalars and nested arrays only (no floats), so the entry hash is reproducible
     */
    public function record(
        AuditAction $action,
        ?PreservationBatch $batch = null,
        ?PreservationBatchItem $item = null,
        array $details = [],
        ?string $sha256Snapshot = null,
        ?string $tenantId = null,
    ): PreservationAuditLog {
        $tenantId ??= $batch->tenant_id ?? $item->tenant_id ?? $this->tenantContext->getTenantId();

        return DB::transaction(function () use ($action, $batch, $item, $details, $sha256Snapshot, $tenantId): PreservationAuditLog {
            $last = PreservationAuditLog::withoutGlobalScopes()->where('tenant_id', $tenantId)->orderByDesc('sequence')->lockForUpdate()->first();
            $sequence = ($last->sequence ?? 0) + 1;
            $createdAt = Carbon::now()->startOfSecond();

            $attributes = [
                'tenant_id' => $tenantId,
                'sequence' => $sequence,
                'preservation_batch_id' => $batch->id ?? $item->preservation_batch_id,
                'preservation_item_id' => $item?->id,
                'actor_id' => auth()->id(),
                'action' => $action->value,
                'details_json' => $details,
                'sha256_snapshot' => $sha256Snapshot,
                'previous_log_sha256' => $last?->entry_sha256,
                'ip_address' => request()->ip(),
                'user_agent' => $this->truncate(request()->userAgent()),
                'created_at' => $createdAt,
            ];
            $attributes['entry_sha256'] = $this->hashOf($attributes, $createdAt);

            $log = new PreservationAuditLog($attributes);
            $log->tenant_id = (string) $tenantId;
            $log->save();

            return $log;
        });
    }

    /**
     * Recompute the whole chain of a tenant.
     *
     * @return array{ok: bool, checked: int, broken_at: ?int}
     */
    public function verifyChain(?string $tenantId = null): array
    {
        $tenantId ??= $this->tenantContext->getTenantId();
        $previous = null;
        $checked = 0;

        foreach (PreservationAuditLog::withoutGlobalScopes()->where('tenant_id', $tenantId)->orderBy('sequence')->cursor() as $log) {
            $expected = $this->hashOf([
                'tenant_id' => $log->tenant_id, 'sequence' => $log->sequence, 'preservation_batch_id' => $log->preservation_batch_id,
                'preservation_item_id' => $log->preservation_item_id, 'actor_id' => $log->actor_id, 'action' => $log->action,
                'details_json' => $log->details_json ?? [], 'sha256_snapshot' => $log->sha256_snapshot, 'previous_log_sha256' => $previous,
            ], $log->created_at);

            if ($log->previous_log_sha256 !== $previous || ! hash_equals($expected, $log->entry_sha256)) {
                return ['ok' => false, 'checked' => $checked, 'broken_at' => $log->sequence];
            }

            $previous = $log->entry_sha256;
            $checked++;
        }

        return ['ok' => true, 'checked' => $checked, 'broken_at' => null];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function hashOf(array $attributes, Carbon $createdAt): string
    {
        $canonical = [
            $attributes['tenant_id'], $attributes['sequence'], $attributes['preservation_batch_id'], $attributes['preservation_item_id'],
            $attributes['actor_id'], $attributes['action'], $attributes['details_json'] ?? [], $attributes['sha256_snapshot'],
            $attributes['previous_log_sha256'], $createdAt->format('Y-m-d H:i:s'),
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function truncate(?string $value): ?string
    {
        return $value === null ? null : mb_substr($value, 0, 500);
    }
}
