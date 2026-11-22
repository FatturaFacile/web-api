<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\MesSync;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Manufacturing\Exceptions\MesSyncException;

/**
 * Serves the pull endpoints of the MES Core: pages of the change log by monotonic sequence cursor and the manifest of
 * `(entity_id, revision)` pairs the MES Core reconciles against. The revision of an entity is the sequence number of its
 * last change, so a replica applies a change only when its revision is higher than the stored one.
 */
final readonly class MesSyncQuery
{
    private const int SchemaVersion = 1;

    public function __construct(private MesSyncPresenter $presenter) {}

    /**
     * A page of changes after the cursor (or after the first change since `$updatedSince` on the first call).
     *
     * @return array<string, mixed>
     */
    public function page(string $tenantId, string $resource, ?string $cursor, ?string $updatedSince, int $limit): array
    {
        $afterSeq = $cursor !== null ? self::decodeCursor($cursor) : null;

        if ($afterSeq === null && $updatedSince !== null) {
            $afterSeq = $this->seqBefore($tenantId, Carbon::parse($updatedSince));
        }

        if ($afterSeq !== null) {
            $this->assertServable($tenantId, $afterSeq);
        }

        $rows = DB::table('mfg_mes_sync_changes')
            ->where('tenant_id', $tenantId)
            ->where('resource', $resource)
            ->where('seq', '>', $afterSeq ?? 0)
            ->orderBy('seq')
            ->limit($limit + 1)
            ->get(['seq', 'entity_id']);

        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        // Several changes of one entity inside the page collapse into the last one: the state is read once.
        $latest = [];
        foreach ($rows as $row) {
            $latest[(string) $row->entity_id] = (int) $row->seq;
        }
        asort($latest);

        $data = $this->presenter->present($resource, $tenantId, array_keys($latest));

        $items = [];
        foreach ($latest as $entityId => $seq) {
            $entity = $data[$entityId] ?? null;
            $items[] = array_filter([
                'operation' => $entity !== null ? MesChangeRecorder::Upsert : MesChangeRecorder::Delete,
                'revision' => $seq,
                'entity_id' => (string) $entityId,
                'data' => $entity,
            ], static fn (mixed $value): bool => $value !== null);
        }

        return [
            'schema_version' => self::SchemaVersion,
            'resource' => $resource,
            'items' => $items,
            'next_cursor' => $rows->isEmpty() ? null : self::encodeCursor((int) $rows->last()->seq),
            'has_more' => $hasMore,
            'watermark' => $this->watermark($tenantId),
        ];
    }

    /**
     * A page of the `(entity_id, revision)` pairs of the resource, ordered by entity id. An entity that is gone or not
     * exposed is listed with `deleted: true`, so the MES Core can drop its replica.
     *
     * @return array<string, mixed>
     */
    public function manifest(string $tenantId, string $resource, ?string $cursor, int $limit): array
    {
        $afterEntity = $cursor !== null ? self::decodeEntityCursor($cursor) : null;

        $rows = DB::table('mfg_mes_sync_changes')
            ->where('tenant_id', $tenantId)
            ->where('resource', $resource)
            ->when($afterEntity !== null, static fn ($query) => $query->where('entity_id', '>', $afterEntity))
            ->groupBy('entity_id')
            ->orderBy('entity_id')
            ->limit($limit + 1)
            ->selectRaw('entity_id, MAX(seq) as revision')
            ->get();

        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $data = $this->presenter->present($resource, $tenantId, $rows->pluck('entity_id')->map(static fn (mixed $id): string => (string) $id)->all());

        $items = $rows->map(static fn (object $row): array => [
            'entity_id' => (string) $row->entity_id,
            'revision' => (int) $row->revision,
            'deleted' => ($data[(string) $row->entity_id] ?? null) === null,
        ])->values()->all();

        return [
            'schema_version' => self::SchemaVersion,
            'resource' => $resource,
            'items' => $items,
            'next_cursor' => $hasMore && $rows->isNotEmpty() ? self::encodeEntityCursor((string) $rows->last()->entity_id) : null,
            'has_more' => $hasMore,
            'watermark' => $this->watermark($tenantId),
        ];
    }

    /**
     * Highest change sequence of the tenant, the point from which a client that reached the end can resume.
     */
    public function watermark(string $tenantId): int
    {
        return (int) DB::table('mfg_mes_sync_changes')->where('tenant_id', $tenantId)->max('seq');
    }

    /**
     * The opaque cursor of a sequence number.
     */
    public static function encodeCursor(int $seq): string
    {
        return rtrim(strtr(base64_encode((string) json_encode(['v' => 1, 'seq' => $seq])), '+/', '-_'), '=');
    }

    /**
     * The sequence number of a cursor; a cursor this API did not issue is refused.
     */
    public static function decodeCursor(string $cursor): int
    {
        $decoded = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);

        if (! is_array($decoded) || ($decoded['v'] ?? null) !== 1 || ! is_int($decoded['seq'] ?? null) || $decoded['seq'] < 0) {
            throw MesSyncException::invalidCursor();
        }

        return $decoded['seq'];
    }

    /**
     * The sequence before the first change at or after the given instant, so the first page starts there. With no change
     * since then the cursor is the end of the log.
     */
    private function seqBefore(string $tenantId, Carbon $since): int
    {
        $first = DB::table('mfg_mes_sync_changes')->where('tenant_id', $tenantId)->where('changed_at', '>=', $since)->min('seq');

        return $first !== null ? max((int) $first - 1, 0) : $this->watermark($tenantId);
    }

    /**
     * A cursor below the pruned part of the log, or beyond its end, cannot be served: the client must bootstrap again.
     */
    private function assertServable(string $tenantId, int $afterSeq): void
    {
        $floor = (int) DB::table('mfg_mes_sync_floors')->where('tenant_id', $tenantId)->value('pruned_through_seq');

        if ($afterSeq < $floor || $afterSeq > (int) DB::table('mfg_mes_sync_changes')->max('seq')) {
            throw MesSyncException::cursorGone();
        }
    }

    private static function encodeEntityCursor(string $entityId): string
    {
        return rtrim(strtr(base64_encode((string) json_encode(['v' => 1, 'entity' => $entityId])), '+/', '-_'), '=');
    }

    private static function decodeEntityCursor(string $cursor): string
    {
        $decoded = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);

        if (! is_array($decoded) || ($decoded['v'] ?? null) !== 1 || ! is_string($decoded['entity'] ?? null)) {
            throw MesSyncException::invalidCursor();
        }

        return $decoded['entity'];
    }
}
