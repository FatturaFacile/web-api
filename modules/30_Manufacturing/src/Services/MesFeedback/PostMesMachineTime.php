<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\MesFeedback;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Manufacturing\Exceptions\MesEventRejectedException;
use Modules\Manufacturing\Models\MfgMachineCenter;
use Modules\Manufacturing\Support\MesSettings;
use Modules\Manufacturing\Support\Money;

/**
 * Posts the machine time of an order snapshot of the MES Core as an append-only DELTA against what earlier revisions
 * already posted, never as an overwrite: a revision that arrives after the period was closed is posted today with the
 * reason `late_revision`, so closed periods stay untouched and the audit trail shows every correction.
 *
 * Only when the tenant takes its machine time from the MES (`machine_time_source = mes`); otherwise the confirmations
 * remain the source and nothing is posted. The machine cost of a delta is its run time at the machine's hourly rate in
 * force when it is posted (the rate is not restated for earlier postings).
 */
final readonly class PostMesMachineTime
{
    /**
     * Posts the delta of the snapshot, if any.
     *
     * @param  array<string, mixed>  $event
     */
    public function __invoke(string $snapshotId, array $event): void
    {
        $tenantId = (string) $event['tenant_id'];

        if (MesSettings::machineTimeSource($tenantId) !== MesSettings::MachineTimeMes) {
            return;
        }

        $machine = MfgMachineCenter::query()->where('tenant_id', $tenantId)->find($event['machine_id']);

        if ($machine === null) {
            throw new MesEventRejectedException('The machine does not exist.', 'machine.unknown');
        }

        $operationId = (string) $event['production_order_operation_id'];
        $metrics = $event['metrics'];

        $target = [
            'run' => $this->minutes($metrics['run_ms'] ?? 0),
            'setup' => $this->minutes($metrics['setup_ms'] ?? 0),
            'downtime' => $this->minutes(Money::add($metrics['alarm_ms'] ?? 0, $metrics['down_ms'] ?? 0)),
            'energy' => isset($metrics['delta_kwh']) ? Money::round($metrics['delta_kwh'], 4) : null,
        ];

        $prior = ['run' => '0', 'setup' => '0', 'downtime' => '0', 'energy' => '0'];
        $postings = DB::table('mfg_mes_machine_time_postings')
            ->where('tenant_id', $tenantId)
            ->where('production_order_operation_id', $operationId)
            ->where('machine_id', $machine->id)
            ->get(['delta_run_minutes', 'delta_setup_minutes', 'delta_downtime_minutes', 'delta_energy_kwh']);

        foreach ($postings as $posting) {
            $prior['run'] = Money::add($prior['run'], $posting->delta_run_minutes);
            $prior['setup'] = Money::add($prior['setup'], $posting->delta_setup_minutes);
            $prior['downtime'] = Money::add($prior['downtime'], $posting->delta_downtime_minutes);
            $prior['energy'] = Money::add($prior['energy'], $posting->delta_energy_kwh);
        }

        $delta = [
            'run' => Money::sub($target['run'], $prior['run']),
            'setup' => Money::sub($target['setup'], $prior['setup']),
            'downtime' => Money::sub($target['downtime'], $prior['downtime']),
            'energy' => $target['energy'] !== null ? Money::sub($target['energy'], $prior['energy']) : null,
        ];

        if (Money::isZero($delta['run']) && Money::isZero($delta['setup']) && Money::isZero($delta['downtime']) && ($delta['energy'] === null || Money::isZero($delta['energy']))) {
            return;
        }

        $revised = $postings->isNotEmpty();

        DB::table('mfg_mes_machine_time_postings')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'production_order_operation_id' => $operationId,
            'machine_id' => $machine->id,
            'snapshot_id' => $snapshotId,
            'revision' => $event['revision'],
            'delta_run_minutes' => Money::round($delta['run'], 2),
            'delta_setup_minutes' => Money::round($delta['setup'], 2),
            'delta_downtime_minutes' => Money::round($delta['downtime'], 2),
            'delta_machine_cost' => Money::round(Money::mul(Money::div($delta['run'], '60'), $machine->hourly_rate), 4),
            'delta_energy_kwh' => $delta['energy'] !== null ? Money::round($delta['energy'], 4) : null,
            'posting_date' => $revised ? now()->toDateString() : Carbon::parse((string) $event['as_of_event_time'])->utc()->toDateString(),
            'reason' => $revised ? 'late_revision' : 'initial',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function minutes(string|int|float $milliseconds): string
    {
        return Money::round(Money::div($milliseconds, '60000'), 2);
    }
}
