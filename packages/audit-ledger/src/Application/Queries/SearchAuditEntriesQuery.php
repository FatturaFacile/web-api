<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Application\Queries;

use Enterprise\AuditLedger\Application\DTOs\AuditEntryData;
use Enterprise\AuditLedger\Domain\Services\Pseudonymizer;
use Enterprise\AuditLedger\Domain\ValueObjects\ClientContext;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Enterprise\AuditLedger\Support\ResourceTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Cursor paginated search of the ledger of a tenant, newest entry first. Every filter is optional:
 * from / to (Y-m-d, UTC days), actor (user id or actor reference), action, resource_type, company_id, channel, ip, category.
 */
final class SearchAuditEntriesQuery
{
    public const int DEFAULT_LIMIT = 50;

    public const int MAX_LIMIT = 200;

    public function __construct(private readonly Pseudonymizer $pseudonymizer) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{entries: Collection<int, AuditEntryData>, next_cursor: ?string}
     */
    public function handle(string $tenantId, array $filters = [], ?string $cursor = null, int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $query = $this->filtered($tenantId, $filters)->orderByDesc('sequence');

        $afterSequence = self::decodeCursor($cursor);

        if ($afterSequence !== null) {
            $query->where('sequence', '<', $afterSequence);
        }

        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit);

        return [
            'entries' => AuditEntryData::collect($tenantId, $page, $this->pseudonymizer),
            'next_cursor' => $hasMore ? self::encodeCursor((int) $page->last()->sequence) : null,
        ];
    }

    /**
     * The filtered query, without order or limit (used by the export package too).
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<AuditLedgerEntry>
     */
    public function filtered(string $tenantId, array $filters): Builder
    {
        $query = AuditLedgerEntry::query()->where('tenant_id', $tenantId);

        if (! empty($filters['from'])) {
            $query->where('event_timestamp', '>=', Carbon::parse((string) $filters['from'], 'UTC')->startOfDay()->format('Y-m-d H:i:s.u').'+00:00');
        }

        if (! empty($filters['to'])) {
            $query->where('event_timestamp', '<=', Carbon::parse((string) $filters['to'], 'UTC')->endOfDay()->format('Y-m-d H:i:s.u').'+00:00');
        }

        if (! empty($filters['actor'])) {
            $actor = (string) $filters['actor'];
            $query->where(static fn (Builder $nested) => $nested->where('actor_ref', $actor)->orWhere('actor_user_id', $actor)->orWhere('impersonator_ref', $actor));
        }

        if (! empty($filters['action'])) {
            $query->where('action', strtoupper((string) $filters['action']));
        }

        if (! empty($filters['resource_type'])) {
            $query->whereIn('resource_type', ResourceTypes::candidates((string) $filters['resource_type']));
        }

        if (! empty($filters['company_id'])) {
            $query->where('company_id', (string) $filters['company_id']);
        }

        if (! empty($filters['channel'])) {
            $query->where('channel', (string) $filters['channel']);
        }

        if (! empty($filters['category'])) {
            $query->where('category', (string) $filters['category']);
        }

        if (! empty($filters['ip'])) {
            // The ledger keeps the truncated address: the filter is truncated the same way.
            $query->where('ip_address', ClientContext::truncateIp((string) $filters['ip']) ?? (string) $filters['ip']);
        }

        return $query;
    }

    public static function encodeCursor(int $sequence): string
    {
        return rtrim(strtr(base64_encode((string) $sequence), '+/', '-_'), '=');
    }

    public static function decodeCursor(?string $cursor): ?int
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        return $decoded !== false && ctype_digit($decoded) ? (int) $decoded : null;
    }
}
