<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Application\Queries;

use Enterprise\AuditLedger\Application\DTOs\AuditEntryData;
use Enterprise\AuditLedger\Application\DTOs\ResourceHistoryItemData;
use Enterprise\AuditLedger\Domain\Services\Pseudonymizer;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Enterprise\AuditLedger\Support\AuditIds;
use Enterprise\AuditLedger\Support\ResourceTypes;
use Illuminate\Support\Collection;

/**
 * The history of one record, newest first: every create, update, delete, restore and business transition recorded for it.
 */
final class GetResourceHistoryQuery
{
    public const int LIMIT = 500;

    public function __construct(private readonly Pseudonymizer $pseudonymizer) {}

    /**
     * @return Collection<int, ResourceHistoryItemData>
     */
    public function handle(string $tenantId, string $resourceType, string $resourceId): Collection
    {
        $rows = AuditLedgerEntry::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('resource_type', ResourceTypes::candidates($resourceType))
            ->where('resource_id', AuditIds::uuidFor($resourceId))
            ->orderByDesc('sequence')
            ->limit(self::LIMIT)
            ->get();

        return AuditEntryData::collect($tenantId, $rows, $this->pseudonymizer)
            ->map(static fn (AuditEntryData $entry): ResourceHistoryItemData => ResourceHistoryItemData::fromEntry($entry))
            ->values();
    }
}
