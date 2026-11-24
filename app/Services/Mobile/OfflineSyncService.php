<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Gate;

/**
 * Incremental (delta) sync feeding the offline Drift/SQLite cache.
 */
final class OfflineSyncService
{
    /** Attributes never sent to a device. */
    private const HIDDEN = ['password', 'remember_token', 'api_token', 'secret', 'file_path', 'read_ip'];

    /** @return array<string, array<string, mixed>> */
    public function entities(): array
    {
        return config('mobile.sync_entities', []);
    }

    public function isAllowed(User $user, string $entity): bool
    {
        $config = $this->entities()[$entity] ?? null;
        if ($config === null) {
            return false;
        }

        if (! empty($config['public'])) {
            return true;
        }

        return Gate::forUser($user)->allows('viewAny', $config['model']);
    }

    /**
     * @return array{data: list<array<string, mixed>>, deleted: list<string>, next_cursor: ?string, server_time: string, has_more: bool}
     */
    public function delta(User $user, string $entity, ?string $updatedSince, ?string $cursor, ?int $limit = null): array
    {
        $config = $this->entities()[$entity];
        /** @var class-string<Model> $modelClass */
        $modelClass = $config['model'];
        $limit = max(1, min($limit ?? (int) config('mobile.sync_page_size'), 500));
        $serverTime = CarbonImmutable::now();

        $query = $modelClass::query();
        $usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($modelClass), true);
        if ($usesSoftDeletes) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }
        if (! empty($config['own'])) {
            $query->where($config['own'], $user->id);
        }

        $since = $updatedSince !== null ? CarbonImmutable::parse($updatedSince) : null;
        if ($since !== null) {
            $query->where('updated_at', '>=', $since);
        }
        if ($cursor !== null) {
            [$cursorTime, $cursorId] = array_pad(explode('|', (string) base64_decode($cursor, true), 2), 2, '');
            $query->where(function ($q) use ($cursorTime, $cursorId): void {
                $q->where('updated_at', '>', $cursorTime)
                    ->orWhere(fn ($q2) => $q2->where('updated_at', $cursorTime)->where('id', '>', $cursorId));
            });
        }

        $rows = $query->orderBy('updated_at')->orderBy('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $data = [];
        $deleted = [];
        foreach ($rows as $row) {
            if ($usesSoftDeletes && method_exists($row, 'trashed') && $row->trashed()) {
                $deleted[] = (string) $row->getKey();

                continue;
            }
            $data[] = collect($row->toArray())->except(self::HIDDEN)->all();
        }

        $last = $rows->last();

        return [
            'data' => $data,
            'deleted' => $deleted,
            'next_cursor' => $hasMore && $last !== null
                ? base64_encode($last->getAttribute('updated_at')->format('Y-m-d H:i:s').'|'.$last->getKey())
                : null,
            'server_time' => $serverTime->toIso8601String(),
            'has_more' => $hasMore,
        ];
    }
}
