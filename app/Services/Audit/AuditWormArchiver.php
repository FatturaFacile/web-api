<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Domain\Audit\Enums\AuditEventType;
use App\Models\AuditLog;
use App\Models\AuditWormBatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Seals the audit records not yet archived into a signed JSONL file plus a SHA-256 manifest on the WORM disk (an S3
 * compatible store with Object Lock in COMPLIANCE mode, or a read-only local fallback). The batch rows live in their own
 * tables: audit_logs itself is never updated.
 */
final class AuditWormArchiver
{
    public function __construct(
        private readonly AuditChainCryptoService $crypto,
        private readonly AuditTrailRecorder $recorder,
    ) {}

    /**
     * Seals the next batch of the tenant; null when every record is already sealed.
     */
    public function seal(?string $tenantId): ?AuditWormBatch
    {
        return Cache::lock('audit_worm:'.($tenantId ?? 'system'), 120)->block(
            30,
            fn (): ?AuditWormBatch => $this->sealLocked($tenantId),
        );
    }

    /**
     * Checks a sealed batch against the storage: the file matches its manifest, the manifest matches the batch row and
     * the Merkle root of the archived hashes matches too.
     *
     * @return array{valid: bool, reason: ?string}
     */
    public function verifyBatch(AuditWormBatch $batch): array
    {
        $disk = Storage::disk($batch->storage_disk);

        if (! $disk->exists($batch->storage_path) || ! $disk->exists($batch->manifest_path)) {
            return ['valid' => false, 'reason' => 'The archive file or its manifest is missing from the WORM storage.'];
        }

        $content = (string) $disk->get($batch->storage_path);
        $manifest = json_decode((string) $disk->get($batch->manifest_path), true);

        if (! is_array($manifest) || ! isset($manifest['file_sha256'], $manifest['signature'])) {
            return ['valid' => false, 'reason' => 'The manifest is unreadable.'];
        }

        $signed = $manifest;
        unset($signed['signature']);

        if (! $this->crypto->verifySignature($this->crypto->canonicalJson($signed), (string) $manifest['signature'])) {
            return ['valid' => false, 'reason' => 'The manifest signature is invalid.'];
        }

        if (! hash_equals((string) $manifest['file_sha256'], hash('sha256', $content))) {
            return ['valid' => false, 'reason' => 'The archive file does not match the SHA-256 of its manifest.'];
        }

        $lines = array_values(array_filter(explode("\n", $content), static fn (string $line): bool => $line !== ''));
        $hashes = array_map(static fn (string $line): string => (string) (json_decode($line, true)['current_hash'] ?? ''), $lines);

        if (count($lines) !== $batch->record_count || $this->crypto->merkleRoot($hashes) !== $batch->merkle_root_hash) {
            return ['valid' => false, 'reason' => 'The archived records do not match the batch (count or Merkle root).'];
        }

        return ['valid' => true, 'reason' => null];
    }

    private function sealLocked(?string $tenantId): ?AuditWormBatch
    {
        $from = (int) $this->batches($tenantId)->max('to_sequence') + 1;
        $pending = $this->logs($tenantId)->where('sequence_number', '>=', $from)->count();

        if ($pending === 0) {
            return null;
        }

        $batchId = (string) Str::uuid7();
        $size = max(1, (int) config('audit.worm.batch_size', 5000));

        if ($pending <= $size) {
            // The seal itself is part of the batch it seals: sealing twice in a row is a no-op.
            $event = $this->recorder->record(
                event: AuditEventType::WormSealed,
                entityType: 'AuditWormBatch',
                entityId: $batchId,
                tenantId: $tenantId,
                context: ['from_sequence' => $from, 'records' => $pending + 1],
            );
            $to = $event->sequence_number;
        } else {
            $to = $from + $size - 1;
        }

        $rows = $this->logs($tenantId)
            ->whereBetween('sequence_number', [$from, $to])
            ->orderBy('sequence_number')
            ->get();

        $lines = [];
        $hashes = [];

        foreach ($rows as $row) {
            $lines[] = $this->crypto->canonicalJson($row->only([
                'id', 'tenant_id', 'sequence_number', 'event_type', 'auditable_type', 'auditable_id', 'actor_type', 'actor_id',
                'actor_email', 'actor_role', 'ip_address', 'user_agent', 'request_id', 'route_name', 'old_values_encrypted',
                'new_values_encrypted', 'changed_fields', 'context_metadata', 'previous_hash', 'current_hash', 'hmac_signature', 'occurred_at',
            ]));
            $hashes[] = $row->current_hash;
        }

        $content = implode("\n", $lines)."\n";
        $root = (string) $this->crypto->merkleRoot($hashes);
        $sealedAt = Carbon::now('UTC');
        $retentionUntil = $sealedAt->copy()->addYears((int) config('audit.worm.retention_years', 10));
        $diskName = (string) config('audit.worm.disk', 'audit_worm');
        $path = sprintf('%s/%s/%012d-%012d.jsonl', trim((string) config('audit.worm.prefix', 'audit-worm'), '/'), $tenantId ?? 'system', $from, $to);
        $manifestPath = $path.'.sha256';

        $manifest = [
            'batch_id' => $batchId,
            'tenant_id' => $tenantId,
            'from_sequence' => $from,
            'to_sequence' => $to,
            'record_count' => $rows->count(),
            'merkle_root_hash' => $root,
            'file' => basename($path),
            'file_sha256' => hash('sha256', $content),
            'sealed_at' => $sealedAt->format('Y-m-d\TH:i:s\Z'),
            'retention_until' => $retentionUntil->format('Y-m-d\TH:i:s\Z'),
        ];
        $manifest['signature'] = $this->crypto->sign($this->crypto->canonicalJson($manifest));

        $this->write($diskName, $path, $content, $retentionUntil);
        $this->write($diskName, $manifestPath, $this->crypto->canonicalJson($manifest), $retentionUntil);

        return DB::transaction(function () use ($batchId, $tenantId, $from, $to, $rows, $root, $diskName, $path, $manifestPath, $retentionUntil, $sealedAt): AuditWormBatch {
            $batch = AuditWormBatch::query()->create([
                'id' => $batchId,
                'tenant_id' => $tenantId,
                'from_sequence' => $from,
                'to_sequence' => $to,
                'record_count' => $rows->count(),
                'merkle_root_hash' => $root,
                'storage_disk' => $diskName,
                'storage_path' => $path,
                'manifest_path' => $manifestPath,
                'retention_until' => $retentionUntil,
                'sealed_at' => $sealedAt,
            ]);

            foreach ($rows->pluck('id')->chunk(500) as $ids) {
                DB::table('audit_worm_batch_entries')->insert(
                    $ids->map(static fn (string $id): array => ['tenant_id' => $tenantId, 'batch_id' => $batchId, 'audit_log_id' => $id])->all(),
                );
            }

            return $batch;
        });
    }

    private function write(string $diskName, string $path, string $content, Carbon $retentionUntil): void
    {
        $disk = Storage::disk($diskName);

        if ($disk->exists($path)) {
            throw new RuntimeException("WORM object {$path} already exists and cannot be overwritten.");
        }

        $isS3 = config("filesystems.disks.{$diskName}.driver") === 's3';

        $written = $isS3
            ? $disk->put($path, $content, [
                'ObjectLockMode' => (string) config('audit.worm.object_lock_mode', 'COMPLIANCE'),
                'ObjectLockRetainUntilDate' => $retentionUntil->toDateTimeString(),
            ])
            : $disk->put($path, $content);

        if ($written === false) {
            throw new RuntimeException("The WORM object {$path} could not be written.");
        }

        if (config("filesystems.disks.{$diskName}.driver") === 'local') {
            @chmod($disk->path($path), 0444);
        }
    }

    /**
     * @return Builder<AuditLog>
     */
    private function logs(?string $tenantId): Builder
    {
        return AuditLog::query()->when(
            $tenantId === null,
            fn (Builder $q) => $q->whereNull('tenant_id'),
            fn (Builder $q) => $q->where('tenant_id', $tenantId),
        );
    }

    /**
     * @return Builder<AuditWormBatch>
     */
    private function batches(?string $tenantId): Builder
    {
        return AuditWormBatch::query()->when(
            $tenantId === null,
            fn (Builder $q) => $q->whereNull('tenant_id'),
            fn (Builder $q) => $q->where('tenant_id', $tenantId),
        );
    }
}
