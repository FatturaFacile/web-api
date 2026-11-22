<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\AnalyticalAccounting\Domain\Services\HierarchyValidatorService;
use Modules\AnalyticalAccounting\Models\AnalyticalCenter;

/**
 * Cost and profit center tree: creation and moves validated by the hierarchy rules, materialized path and depth kept in
 * step, tree and flat read models, and a safe archive that never loses a center referenced by historical postings.
 */
final class CenterService
{
    public function __construct(private readonly HierarchyValidatorService $hierarchy) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data, ?string $userId = null): AnalyticalCenter
    {
        return DB::transaction(function () use ($tenantId, $data, $userId): AnalyticalCenter {
            $center = new AnalyticalCenter(['tenant_id' => $tenantId, 'is_active' => true, 'created_by' => $userId, ...$data]);
            $this->assertCenterRules($tenantId, (string) $center->id, $data);
            $center->save();
            $this->refreshPaths($tenantId);

            return $center->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(AnalyticalCenter $center, array $data, ?string $userId = null): AnalyticalCenter
    {
        return DB::transaction(function () use ($center, $data, $userId): AnalyticalCenter {
            $merged = [...$center->only(['parent_id', 'center_type', 'valid_from', 'valid_to']), ...$data];
            $this->assertCenterRules((string) $center->tenant_id, (string) $center->id, $merged);
            $center->fill([...$data, 'updated_by' => $userId])->save();
            $this->refreshPaths((string) $center->tenant_id);

            return $center->refresh();
        });
    }

    /**
     * Deactivates a center and, when requested, its whole subtree.
     */
    public function deactivate(AnalyticalCenter $center, bool $withDescendants = false, ?string $userId = null): void
    {
        $ids = [(string) $center->id];
        if ($withDescendants) {
            $ids = [...$ids, ...$this->hierarchy->descendantIds($this->flatTree((string) $center->tenant_id), (string) $center->id)];
        }
        AnalyticalCenter::query()->where('tenant_id', $center->tenant_id)->whereIn('id', $ids)->update(['is_active' => false, 'updated_by' => $userId]);
    }

    /**
     * @throws InvalidArgumentException when postings, children or schedule steps still reference the center
     */
    public function delete(AnalyticalCenter $center): void
    {
        $tenant = (string) $center->tenant_id;
        $id = (string) $center->id;
        if (AnalyticalCenter::query()->where('tenant_id', $tenant)->where('parent_id', $id)->exists()) {
            throw new InvalidArgumentException('A center with children cannot be deleted.');
        }
        if ($this->isReferencedByPostings($tenant, $id)) {
            throw new InvalidArgumentException('A center used by analytical postings cannot be deleted: deactivate it instead.');
        }
        if (DB::table('apportionment_schedule_steps')->where('tenant_id', $tenant)->where('source_center_id', $id)->whereNull('deleted_at')->exists()) {
            throw new InvalidArgumentException('A center used by an apportionment schedule cannot be deleted.');
        }
        $center->delete();
    }

    public function isReferencedByPostings(string $tenantId, string $centerId): bool
    {
        return DB::table('analytical_entry_lines')->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->where(static fn ($q) => $q->where('cost_center_id', $centerId)->orWhere('profit_center_id', $centerId))
            ->exists()
            || DB::table('apportionment_run_allocations')->where('tenant_id', $tenantId)->whereNull('deleted_at')
                ->where(static fn ($q) => $q->where('source_center_id', $centerId)->orWhere('target_center_id', $centerId))
                ->exists();
    }

    /**
     * Nested tree, optionally limited to the subtree of a root.
     *
     * @return list<array<string, mixed>>
     */
    public function tree(string $tenantId, ?string $rootId = null, bool $activeOnly = false): array
    {
        $rows = $this->rows($tenantId, $activeOnly);
        $children = [];
        foreach ($rows as $row) {
            $children[(string) ($row['parent_id'] ?? '')][] = $row;
        }
        $build = function (string $parent) use (&$build, $children): array {
            return array_map(fn (array $row): array => $row + ['children' => $build((string) $row['id'])], $children[$parent] ?? []);
        };
        if ($rootId !== null) {
            $root = collect($rows)->firstWhere('id', $rootId);

            return $root === null ? [] : [$root + ['children' => $build($rootId)]];
        }

        return $build('');
    }

    /**
     * Flat list ordered depth-first by code, each row with its depth and path.
     *
     * @return list<array<string, mixed>>
     */
    public function flat(string $tenantId, bool $activeOnly = false): array
    {
        $out = [];
        $walk = function (array $nodes) use (&$walk, &$out): void {
            foreach ($nodes as $node) {
                $children = $node['children'];
                unset($node['children']);
                $out[] = $node;
                $walk($children);
            }
        };
        $walk($this->tree($tenantId, null, $activeOnly));

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function ancestors(string $tenantId, string $id): array
    {
        $tree = $this->flatTree($tenantId);
        $ids = array_slice($this->hierarchy->ancestorIds($tree, $id), 1);

        return array_values(array_filter($this->rows($tenantId), static fn (array $row): bool => in_array($row['id'], $ids, true)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function descendants(string $tenantId, string $id): array
    {
        $ids = $this->hierarchy->descendantIds($this->flatTree($tenantId), $id);

        return array_values(array_filter($this->rows($tenantId), static fn (array $row): bool => in_array($row['id'], $ids, true)));
    }

    /**
     * Id of the center plus every descendant, for hierarchy roll-ups.
     *
     * @return list<string>
     */
    public function subtreeIds(string $tenantId, string $id): array
    {
        return [$id, ...$this->hierarchy->descendantIds($this->flatTree($tenantId), $id)];
    }

    /**
     * @return array<string, array{parent_id: string|null, center_type: string, valid_from: string|null, valid_to: string|null, is_active: bool, category: string}>
     */
    public function flatTree(string $tenantId): array
    {
        $tree = [];
        foreach (AnalyticalCenter::query()->where('tenant_id', $tenantId)->get() as $center) {
            $tree[(string) $center->id] = [
                'parent_id' => $center->parent_id === null ? null : (string) $center->parent_id,
                'center_type' => (string) $center->center_type,
                'valid_from' => $center->valid_from?->toDateString(),
                'valid_to' => $center->valid_to?->toDateString(),
                'is_active' => (bool) $center->is_active,
                'category' => (string) $center->category,
            ];
        }

        return $tree;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertCenterRules(string $tenantId, string $id, array $data): void
    {
        $tree = $this->flatTree($tenantId);
        $this->hierarchy->assertValid($tree, $id, [
            'parent_id' => ($data['parent_id'] ?? null) === null ? null : (string) $data['parent_id'],
            'center_type' => (string) ($data['center_type'] ?? 'COST_CENTER'),
            'valid_from' => $this->day($data['valid_from'] ?? null),
            'valid_to' => $this->day($data['valid_to'] ?? null),
        ]);
    }

    private function day(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : Carbon::parse($value)->toDateString();
    }

    private function refreshPaths(string $tenantId): void
    {
        $tree = $this->flatTree($tenantId);
        foreach ($tree as $id => $node) {
            AnalyticalCenter::query()->where('tenant_id', $tenantId)->whereKey($id)->update([
                'path' => $this->hierarchy->pathOf($tree, (string) $id),
                'depth' => $this->hierarchy->depthOf($tree, (string) $id),
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $tenantId, bool $activeOnly = false): array
    {
        $query = AnalyticalCenter::query()->where('tenant_id', $tenantId)->orderBy('code');
        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query->get()->map(static fn (AnalyticalCenter $c): array => [
            'id' => (string) $c->id,
            'parent_id' => $c->parent_id === null ? null : (string) $c->parent_id,
            'code' => (string) $c->code,
            'name' => (string) $c->name,
            'center_type' => (string) $c->center_type,
            'category' => (string) $c->category,
            'depth' => (int) $c->depth,
            'path' => (string) $c->path,
            'manager_user_id' => $c->manager_user_id,
            'manager_name' => $c->manager_name,
            'is_active' => (bool) $c->is_active,
            'valid_from' => $c->valid_from?->toDateString(),
            'valid_to' => $c->valid_to?->toDateString(),
        ])->all();
    }
}
