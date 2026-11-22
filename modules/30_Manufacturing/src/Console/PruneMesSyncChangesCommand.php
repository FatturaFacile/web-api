<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Compacts the change log the MES Core pulls from. A change that a newer change of the same entity superseded is dropped
 * once it is old enough: the newer one still tells a replica everything. A tombstone is dropped too, and the highest
 * tombstone sequence of the tenant becomes its floor, because a replica that has not seen the deletion can no longer be
 * served from the log (its cursor answers 410 Gone and it bootstraps again). Entities are never touched.
 */
final class PruneMesSyncChangesCommand extends Command
{
    protected $signature = 'mfg:mes-prune-changes {--days= : Age in days (default: manufacturing_mes.changes_retention_days)}';

    protected $description = 'Compact the change log of the manufacturing reference data pulled by the MES Core (issue #184)';

    /**
     * Compacts the log and records the floors.
     */
    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('manufacturing_mes.changes_retention_days'));
        $cutoff = now()->subDays($days);

        $superseded = DB::table('mfg_mes_sync_changes')
            ->where('changed_at', '<', $cutoff)
            ->whereNotIn('seq', static fn ($latest) => $latest->from('mfg_mes_sync_changes')->selectRaw('MAX(seq)')->groupBy('tenant_id', 'resource', 'entity_id'))
            ->delete();

        $tombstones = DB::table('mfg_mes_sync_changes')
            ->where('operation', 'delete')
            ->where('changed_at', '<', $cutoff)
            ->selectRaw('tenant_id, MAX(seq) as max_seq')
            ->groupBy('tenant_id')
            ->get();

        foreach ($tombstones as $tombstone) {
            $floor = DB::table('mfg_mes_sync_floors')->where('tenant_id', $tombstone->tenant_id)->first();
            $through = max((int) $tombstone->max_seq, (int) ($floor->pruned_through_seq ?? 0));

            if ($floor === null) {
                DB::table('mfg_mes_sync_floors')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tombstone->tenant_id, 'pruned_through_seq' => $through, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table('mfg_mes_sync_floors')->where('id', $floor->id)->update(['pruned_through_seq' => $through, 'updated_at' => now()]);
            }
        }

        $removedTombstones = DB::table('mfg_mes_sync_changes')->where('operation', 'delete')->where('changed_at', '<', $cutoff)->delete();

        $this->info("Dropped {$superseded} superseded change(s) and {$removedTombstones} tombstone(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
