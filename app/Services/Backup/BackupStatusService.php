<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Models\TenantBackup;

/**
 * Disaster recovery status of a tenant against the recovery point objective (`backup.rpo_hours`). Callers decide the
 * tenant scope (the tenant context for the API, `forTenant` for the scheduled check).
 */
class BackupStatusService
{
    /**
     * @return array<string, mixed>
     */
    public function forTenant(?string $tenantId = null): array
    {
        $query = fn () => $tenantId === null
            ? TenantBackup::query()
            : TenantBackup::withoutGlobalScopes()->where('tenant_id', $tenantId);

        $lastCompleted = $query()->where('status', TenantBackup::COMPLETED)->latest('completed_at')->first();
        $lastFailed = $query()->where('status', TenantBackup::FAILED)->latest()->first();
        $rpoHours = (int) config('backup.rpo_hours', 24);
        $ageHours = $lastCompleted?->completed_at !== null ? (int) floor($lastCompleted->completed_at->diffInHours(now(), true)) : null;

        $state = match (true) {
            $lastCompleted === null => $lastFailed !== null ? 'failing' : 'never',
            $ageHours > $rpoHours => 'stale',
            $lastFailed !== null && $lastFailed->created_at > $lastCompleted->completed_at => 'failing',
            default => 'ok',
        };

        return [
            'state' => $state,
            'last_completed_at' => $lastCompleted?->completed_at?->toIso8601String(),
            'last_completed_size_bytes' => $lastCompleted?->file_size_bytes,
            'age_hours' => $ageHours,
            'last_failed_at' => $lastFailed?->created_at?->toIso8601String(),
            'last_error' => $lastFailed?->error_message,
            'pending' => $query()->whereIn('status', [TenantBackup::PENDING, TenantBackup::PROCESSING])->count(),
            'completed_count' => $query()->where('status', TenantBackup::COMPLETED)->count(),
            'rpo_hours' => $rpoHours,
            'rto_minutes' => (int) config('backup.rto_minutes', 240),
            'retention' => (int) config('backup.retention', 7),
            'encrypted' => (bool) config('backup.encryption.enabled', true),
        ];
    }
}
