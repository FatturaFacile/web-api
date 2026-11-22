<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\MesFeedback;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Applies the OEE snapshot events of the MES Core (`OeeShiftClosed`, `OeeOrderCompleted`, `OeeSnapshotRevised`).
 *
 * - An event id is processed once (inbox); the same event again is a `duplicate`.
 * - A snapshot is identified by (machine, scope, scope key) and carries a revision: an event whose revision is not
 *   higher than the stored one is `stale` (it arrived late), so deliveries may be out of order.
 * - Every applied revision is kept in an append-only table for the audit trail.
 * - An order snapshot also posts the machine-time delta and proposes the production counts. It never changes the
 *   order or stock quantities: counts only become proposals an operator confirms.
 */
final readonly class ApplyMesOeeSnapshot
{
    public function __construct(
        private MesInbox $inbox,
        private PostMesMachineTime $postMachineTime,
        private ProposeMesProductionCounts $proposeCounts,
    ) {}

    /**
     * Applies a validated event of the tenant.
     *
     * @param  array<string, mixed>  $event
     */
    public function __invoke(array $event): MesEventStatus
    {
        try {
            return DB::transaction(fn (): MesEventStatus => $this->apply($event));
        } catch (UniqueConstraintViolationException) {
            // Two deliveries raced on the same snapshot: the loser is retried once and then resolves as stale or duplicate.
            return DB::transaction(fn (): MesEventStatus => $this->apply($event));
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function apply(array $event): MesEventStatus
    {
        $tenantId = (string) $event['tenant_id'];

        if (! $this->inbox->claim((string) $event['event_id'], $tenantId, (string) $event['event_type'])) {
            return MesEventStatus::Duplicate;
        }

        // lockForUpdate is a row lock on PostgreSQL and a no-op on SQLite (single writer).
        $latest = DB::table('mfg_mes_oee_snapshots')
            ->where('tenant_id', $tenantId)
            ->where('machine_id', $event['machine_id'])
            ->where('scope', $event['scope'])
            ->where('scope_key', $event['scope_key'])
            ->lockForUpdate()
            ->first();

        if ($latest !== null && (int) $latest->revision >= (int) $event['revision']) {
            return MesEventStatus::Stale;
        }

        $snapshotId = $latest !== null ? (string) $latest->id : (string) Str::uuid7();
        $metrics = json_encode($event['metrics'], JSON_THROW_ON_ERROR);

        DB::table('mfg_mes_oee_snapshot_revisions')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'snapshot_id' => $snapshotId,
            'event_id' => $event['event_id'],
            'revision' => $event['revision'],
            'metrics' => $metrics,
            'received_at' => now(),
        ]);

        $row = [
            'tenant_id' => $tenantId,
            'machine_id' => $event['machine_id'],
            'production_order_operation_id' => $event['production_order_operation_id'] ?? null,
            'scope' => $event['scope'],
            'scope_key' => $event['scope_key'],
            'revision' => $event['revision'],
            'policy_version' => $event['policy_version'],
            'metrics' => $metrics,
            'as_of_event_time' => $event['as_of_event_time'],
            'updated_at' => now(),
        ];

        if ($latest === null) {
            DB::table('mfg_mes_oee_snapshots')->insert($row + ['id' => $snapshotId, 'created_at' => now()]);
        } else {
            DB::table('mfg_mes_oee_snapshots')->where('id', $snapshotId)->update($row);
        }

        if ($event['scope'] === 'Order' && ! empty($event['production_order_operation_id'])) {
            ($this->postMachineTime)($snapshotId, $event);
            ($this->proposeCounts)(
                $tenantId,
                (string) $event['production_order_operation_id'],
                (string) ($event['metrics']['good_count'] ?? 0),
                (string) ($event['metrics']['scrap_count'] ?? 0),
                (string) $event['as_of_event_time'],
            );
        }

        return MesEventStatus::Applied;
    }
}
