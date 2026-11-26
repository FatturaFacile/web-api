<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Application\Commands;

use Enterprise\AuditLedger\Domain\Events\AuditCheckpointCreated;
use Enterprise\AuditLedger\Domain\Services\AuditKeyring;
use Enterprise\AuditLedger\Jobs\AnchorCheckpointJob;
use Enterprise\AuditLedger\Models\AuditCheckpoint;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CreateCheckpointHandler
{
    public function __construct(private readonly AuditKeyring $keyring) {}

    /**
     * The checkpoint of the period, created now or already taken (a daily checkpoint is idempotent per UTC day). Null when
     * the tenant has no entry yet.
     */
    public function handle(CreateCheckpointCommand $command): ?AuditCheckpoint
    {
        if (! in_array($command->type, [AuditCheckpoint::TYPE_DAILY, AuditCheckpoint::TYPE_PERIOD], true)) {
            throw new InvalidArgumentException("Unknown checkpoint type [{$command->type}].");
        }

        $now = Carbon::now('UTC');
        $periodRef = $command->periodRef ?? ($command->type === AuditCheckpoint::TYPE_DAILY ? $now->format('Y-m-d') : null);

        $existing = AuditCheckpoint::query()
            ->where('tenant_id', $command->tenantId)->where('type', $command->type)->where('period_ref', $periodRef)->first();

        if ($existing instanceof AuditCheckpoint && $periodRef !== null) {
            return $existing;
        }

        $tail = AuditLedgerEntry::query()->where('tenant_id', $command->tenantId)->whereNotNull('sequence')->orderByDesc('sequence')->first();

        if (! $tail instanceof AuditLedgerEntry) {
            return null;
        }

        $keyVersion = $this->keyring->activeVersion($command->tenantId);
        $createdAt = $now->format('Y-m-d\TH:i:s.u\Z');

        $checkpoint = AuditCheckpoint::query()->create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $command->tenantId,
            'type' => $command->type,
            'period_ref' => $periodRef,
            'sequence' => (int) $tail->sequence,
            'hash' => (string) $tail->record_hash,
            'signature' => self::sign($this->keyring, $command->tenantId, $keyVersion, $command->type, $periodRef, (int) $tail->sequence, (string) $tail->record_hash, $createdAt),
            'key_version' => $keyVersion,
            'created_at' => $now,
        ]);

        AuditCheckpointCreated::dispatch((string) $checkpoint->id, $command->tenantId, $command->type, (int) $checkpoint->sequence, (string) $checkpoint->hash);

        if (config('audit_ledger.anchor.enabled')) {
            AnchorCheckpointJob::dispatch((string) $checkpoint->id)->afterCommit();
        }

        return $checkpoint;
    }

    public static function sign(AuditKeyring $keyring, string $tenantId, int $keyVersion, string $type, ?string $periodRef, int $sequence, string $hash, string $createdAt): string
    {
        return hash_hmac('sha256', implode('|', ['checkpoint', $tenantId, $type, (string) $periodRef, $sequence, $hash, $createdAt]), $keyring->chainKey($tenantId, $keyVersion));
    }
}
