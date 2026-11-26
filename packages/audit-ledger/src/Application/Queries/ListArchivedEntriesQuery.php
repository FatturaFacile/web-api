<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Application\Queries;

use Enterprise\AuditLedger\Application\DTOs\AuditEntryData;
use Enterprise\AuditLedger\Domain\Services\IntegrityVerifier;
use Enterprise\AuditLedger\Domain\Services\Pseudonymizer;
use Enterprise\AuditLedger\Models\AuditLedgerArchiveEntry;
use Enterprise\AuditLedger\Support\ChainReader;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The entries a restore moved to the archive (#230: a restore never replaces the active ledger). Without a restore id it
 * lists the restores of the tenant; with one it pages through its entries, oldest first, and can verify its chain.
 */
final class ListArchivedEntriesQuery
{
    public const int DEFAULT_LIMIT = 100;

    public const int MAX_LIMIT = 500;

    public function __construct(
        private readonly Pseudonymizer $pseudonymizer,
        private readonly IntegrityVerifier $verifier,
        private readonly ChainReader $reader,
    ) {}

    /**
     * @return Collection<int, array{restore_id: string, archived_at: string|null, entries: int}>
     */
    public function restores(string $tenantId): Collection
    {
        return DB::table('enterprise_audit_ledger_archive')
            ->where('tenant_id', $tenantId)
            ->groupBy('restore_id')
            ->selectRaw('restore_id, MIN(archived_at) as archived_at, COUNT(*) as entries')
            ->orderByDesc('archived_at')
            ->get()
            ->map(static fn (object $row): array => ['restore_id' => (string) $row->restore_id, 'archived_at' => $row->archived_at !== null ? (string) $row->archived_at : null, 'entries' => (int) $row->entries]);
    }

    /**
     * @return array{entries: Collection<int, AuditEntryData>, next_cursor: ?string, verification: ?array<string, mixed>}
     */
    public function entries(string $tenantId, string $restoreId, ?string $cursor = null, int $limit = self::DEFAULT_LIMIT, bool $verify = false): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $offset = $cursor !== null && ctype_digit($cursor) ? (int) $cursor : 0;

        $rows = AuditLedgerArchiveEntry::query()
            ->where('tenant_id', $tenantId)
            ->where('restore_id', $restoreId)
            ->orderBy('sequence')->orderBy('event_timestamp')->orderBy('id')
            ->offset($offset)->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;

        return [
            'entries' => AuditEntryData::collect($tenantId, $rows->take($limit), $this->pseudonymizer),
            'next_cursor' => $hasMore ? (string) ($offset + $limit) : null,
            'verification' => $verify ? $this->verifier->verify($this->reader->entries($tenantId, $restoreId))->toArray() : null,
        ];
    }
}
