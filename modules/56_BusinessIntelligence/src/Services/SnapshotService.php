<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Services;

use App\Models\User;
use App\Services\Tenancy\TenantClock;
use App\Services\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Modules\BusinessIntelligence\Models\BiDashboard;
use Modules\BusinessIntelligence\Models\BiSavedQuery;
use Modules\BusinessIntelligence\Models\BiSnapshot;
use Throwable;

/**
 * Snapshots of saved queries and dashboards: the figures of a day kept as they were. A scheduled snapshot runs with the
 * permissions of the owner of the query at the moment it runs: an owner who left the company or lost the dataset gets a
 * failed snapshot recording why, never the data. Scheduled snapshots are idempotent per day, a failure moves the next
 * date forward like a success (it is not retried every hour), and only the most recent snapshots of a source are kept.
 */
final readonly class SnapshotService
{
    /**
     * Inject the collaborators.
     */
    public function __construct(
        private SavedQueryService $savedQueries,
        private DashboardService $dashboards,
        private TenantClock $clock,
        private TenantContext $tenantContext,
    ) {}

    /**
     * Takes a snapshot of a saved query for the user. Errors surface to the caller.
     *
     * @throws ValidationException|AuthorizationException
     */
    public function takeForQuery(BiSavedQuery $query, User $user, string $trigger = 'manual'): BiSnapshot
    {
        $outcome = $this->savedQueries->run($query, $user);

        return $this->store(BiSnapshot::SavedQuery, $query->id, $user, $trigger, [
            'columns' => $outcome['result']['columns'],
            'rows' => $outcome['result']['rows'],
            'meta' => $outcome['result']['meta'],
            'pivot' => $outcome['pivot'],
        ], count($outcome['result']['rows']));
    }

    /**
     * Takes a snapshot of every widget of a dashboard for the user.
     */
    public function takeForDashboard(BiDashboard $dashboard, User $user, string $trigger = 'manual'): BiSnapshot
    {
        $widgets = $dashboard->widgets()->get()->keyBy('id');
        $data = $this->dashboards->data($dashboard, $user);
        $rows = 0;
        $payload = [];

        foreach ($data as $entry) {
            $rows += count($entry['result']['rows'] ?? []);
            $payload[] = [...$entry, 'title' => $widgets[$entry['widget_id']]->title];
        }

        return $this->store(BiSnapshot::Dashboard, $dashboard->id, $user, $trigger, ['widgets' => $payload], $rows);
    }

    /**
     * Takes the scheduled snapshots of the active company that are due today.
     *
     * @return array{succeeded: int, failed: int}
     */
    public function runDue(): array
    {
        $today = $this->clock->today();
        $outcome = ['succeeded' => 0, 'failed' => 0];
        $due = BiSavedQuery::query()->whereNotNull('snapshot_frequency')->whereDate('next_snapshot_on', '<=', $today->toDateString())->get();

        foreach ($due as $query) {
            $failure = null;

            try {
                $this->runScheduled($query);
            } catch (Throwable $exception) {
                report($exception);
                $failure = mb_substr($exception->getMessage(), 0, 500);
                $this->store(BiSnapshot::SavedQuery, $query->id, null, 'scheduled', null, 0, $failure);
            }

            $query->update(['next_snapshot_on' => $this->savedQueries->nextSnapshotAfter($today, (string) $query->snapshot_frequency)->toDateString()]);
            $failure === null ? $outcome['succeeded']++ : $outcome['failed']++;
        }

        return $outcome;
    }

    /**
     * Compares a snapshot of a saved query with the figures of today: for each row (the combination of dimension values)
     * the value then, the value now and the difference of every measure the snapshot holds.
     *
     * @return array{columns: list<array<string, mixed>>, rows: list<array<string, mixed>>, taken_on: string}
     *
     * @throws ValidationException|AuthorizationException
     */
    public function compareWithCurrent(BiSnapshot $snapshot, BiSavedQuery $query, User $user): array
    {
        $payload = (array) $snapshot->payload;
        $dimensions = array_values(array_filter((array) ($payload['columns'] ?? []), static fn (array $column): bool => $column['kind'] === 'dimension'));
        $measures = array_values(array_filter((array) ($payload['columns'] ?? []), static fn (array $column): bool => $column['kind'] === 'measure'));
        $current = $this->savedQueries->run($query, $user)['result']['rows'];
        $keyOf = static fn (array $row): string => json_encode(array_map(static fn (array $column): mixed => $row[$column['key']] ?? null, $dimensions), JSON_THROW_ON_ERROR);
        $now = [];

        foreach ($current as $row) {
            $now[$keyOf($row)] = $row;
        }

        $rows = [];
        $seen = [];

        foreach ((array) ($payload['rows'] ?? []) as $row) {
            $key = $keyOf($row);
            $seen[$key] = true;
            $rows[] = $this->compared($row, $now[$key] ?? null, $dimensions, $measures);
        }

        foreach ($now as $key => $row) {
            if (! isset($seen[$key])) {
                $rows[] = $this->compared(null, $row, $dimensions, $measures);
            }
        }

        return ['columns' => [...$dimensions, ...$measures], 'rows' => $rows, 'taken_on' => $snapshot->taken_on->toDateString()];
    }

    /**
     * @throws ValidationException|AuthorizationException
     */
    private function runScheduled(BiSavedQuery $query): void
    {
        $tenantId = (string) $this->tenantContext->getTenantId();
        $owner = User::query()->find($query->owner_id) ?? throw new AuthorizationException(__('bi_errors.snapshot.owner_gone'));

        if ($owner->getRoleInTenant($tenantId) === null) {
            throw new AuthorizationException(__('bi_errors.snapshot.owner_left'));
        }

        if (BiSnapshot::query()->where('source_type', BiSnapshot::SavedQuery)->where('source_id', $query->id)->where('trigger', 'scheduled')->where('status', BiSnapshot::Ok)->whereDate('taken_on', $this->clock->today()->toDateString())->exists()) {
            return;
        }

        $this->takeForQuery($query, $owner, 'scheduled');
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function store(string $sourceType, string $sourceId, ?User $user, string $trigger, ?array $payload, int $rowCount, ?string $error = null): BiSnapshot
    {
        $snapshot = BiSnapshot::create([
            'tenant_id' => (string) $this->tenantContext->getTenantId(),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'taken_by' => $user?->id,
            'taken_on' => $this->clock->today()->toDateString(),
            'trigger' => $trigger,
            'status' => $error === null ? BiSnapshot::Ok : BiSnapshot::Failed,
            'error' => $error,
            'row_count' => $rowCount,
            'payload' => $payload,
        ]);

        $this->prune($sourceType, $sourceId);

        return $snapshot;
    }

    private function prune(string $sourceType, string $sourceId): void
    {
        $keep = max(1, (int) config('business_intelligence.snapshots_keep', 60));
        $surplus = BiSnapshot::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->orderByDesc('created_at')->orderByDesc('id')->skip($keep)->take(1000)->pluck('id');

        if ($surplus->isNotEmpty()) {
            BiSnapshot::query()->whereIn('id', $surplus->all())->forceDelete();
        }
    }

    /**
     * @param  array<string, mixed>|null  $then
     * @param  array<string, mixed>|null  $now
     * @param  list<array<string, mixed>>  $dimensions
     * @param  list<array<string, mixed>>  $measures
     * @return array<string, mixed>
     */
    private function compared(?array $then, ?array $now, array $dimensions, array $measures): array
    {
        $source = $then ?? $now ?? [];
        $row = [];

        foreach ($dimensions as $column) {
            $row[$column['key']] = $source[$column['key']] ?? null;
        }

        foreach ($measures as $column) {
            $before = $then[$column['key']] ?? null;
            $after = $now[$column['key']] ?? null;
            $scale = max(strlen((string) substr((string) strrchr((string) $before, '.'), 1)), strlen((string) substr((string) strrchr((string) $after, '.'), 1)));
            $row[$column['key']] = [
                'snapshot' => $before,
                'current' => $after,
                'change' => $before !== null && $after !== null ? bcsub((string) $after, (string) $before, $scale) : null,
            ];
        }

        return $row;
    }
}
