<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Support;

use Enterprise\AuditLedger\Models\AuditCheckpoint;
use Enterprise\AuditLedger\Models\AuditLedgerArchiveEntry;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Generator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reads the chain of a tenant (or the archived chain of one restore) in sequence order, in chunks, so that a long chain
 * is verified or exported without loading it in memory.
 */
final class ChainReader
{
    /**
     * @return Generator<int, AuditLedgerEntry|AuditLedgerArchiveEntry>
     */
    public function entries(string $tenantId, ?string $restoreId = null, ?int $fromSequence = null, ?int $toSequence = null): Generator
    {
        $base = fn (): Builder => $this->query($tenantId, $restoreId);

        if ($base()->whereNull('sequence')->exists()) {
            // Entries archived from a backup that predates the sequence: the order of the legacy chain is event time then id.
            $position = 0;

            foreach ($base()->orderBy('event_timestamp')->orderBy('id')->cursor() as $entry) {
                $position++;
                $entry->setAttribute('sequence', $entry->sequence ?? $position);

                yield $entry;
            }

            return;
        }

        $last = ($fromSequence ?? 1) - 1;
        $chunk = max(100, (int) config('audit_ledger.verification.chunk_size', 1000));

        while (true) {
            $rows = $base()->where('sequence', '>', $last)
                ->when($toSequence !== null, fn (Builder $query) => $query->where('sequence', '<=', $toSequence))
                ->orderBy('sequence')->limit($chunk)->get();

            if ($rows->isEmpty()) {
                return;
            }

            foreach ($rows as $row) {
                yield $row;
            }

            $last = (int) $rows->last()->sequence;
        }
    }

    /**
     * The hashes pinned by the checkpoints of the tenant, by sequence.
     *
     * @return array<int, string>
     */
    public function anchors(string $tenantId): array
    {
        return AuditCheckpoint::query()->where('tenant_id', $tenantId)->pluck('hash', 'sequence')
            ->mapWithKeys(static fn (string $hash, int|string $sequence): array => [(int) $sequence => $hash])->all();
    }

    /**
     * @return Builder<AuditLedgerEntry>|Builder<AuditLedgerArchiveEntry>
     */
    private function query(string $tenantId, ?string $restoreId): Builder
    {
        if ($restoreId !== null) {
            return AuditLedgerArchiveEntry::query()->where('tenant_id', $tenantId)->where('restore_id', $restoreId);
        }

        return AuditLedgerEntry::query()->where('tenant_id', $tenantId);
    }
}
