<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\AssetManagement\Models\MaintenanceWorkOrder;
use Modules\Manufacturing\Models\MfgMachineCenter;
use Modules\Manufacturing\Models\MfgShiftInstance;
use Modules\Manufacturing\Models\MfgWorkCenterShift;
use Modules\Manufacturing\Services\MesSync\MaterialiseShiftInstancesAction;

require_once __DIR__.'/MesTestSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    mesSetUp($this);
    config(['manufacturing_mes.shifts' => [
        'timezone' => 'Europe/Rome', 'start_time' => '06:00', 'code' => 'DAY', 'horizon_days' => 3,
        'breaks' => [['start' => '10:00', 'minutes' => 15], ['start' => '23:00', 'minutes' => 30]],
    ]]);

    foreach ([1, 2, 3, 4, 5] as $weekday) {
        MfgWorkCenterShift::create(['tenant_id' => $this->tenant->id, 'work_center_id' => $this->workCenter->id, 'weekday' => $weekday, 'available_hours' => '8']);
    }

    MfgShiftInstance::withTrashed()->forceDelete();
});

/**
 * The non-production windows of the shift that starts at the given UTC time.
 *
 * @return list<array<string, mixed>>
 */
function mesHoles(string $startsAt): array
{
    $instance = MfgShiftInstance::query()->where('starts_at', $startsAt)->firstOrFail();

    return $instance->non_production ?? [];
}

test('the breaks of the plant are excluded from every shift that contains them', function (): void {
    // Friday 2027-01-08, UTC+1: the shift is 05:00-13:00 UTC, the 10:00 local break 09:00-09:15 UTC; 23:00 is outside the shift.
    app(MaterialiseShiftInstancesAction::class)((string) $this->tenant->id, null, CarbonImmutable::parse('2027-01-08 12:00', 'UTC'));

    expect(mesHoles('2027-01-08 05:00:00'))->toBe([
        ['kind' => 'break', 'machine_id' => null, 'from' => '2027-01-08T09:00:00Z', 'to' => '2027-01-08T09:15:00Z', 'reference' => null],
    ]);
});

test('planned maintenance of a machine becomes a window of that machine only, corrective work does not', function (): void {
    $asset = FixedAsset::factory()->capitalized()->create(['tenant_id' => $this->tenant->id]);
    $this->machine->update(['asset_id' => $asset->id]);
    $other = MfgMachineCenter::factory()->create(['tenant_id' => $this->tenant->id, 'work_center_id' => $this->workCenter->id]);

    MaintenanceWorkOrder::factory()->create(['tenant_id' => $this->tenant->id, 'asset_id' => $asset->id, 'work_order_number' => 'MWO-PLAN', 'scheduled_date' => '2027-01-08', 'downtime_hours' => 2]);
    MaintenanceWorkOrder::factory()->create(['tenant_id' => $this->tenant->id, 'asset_id' => $asset->id, 'work_order_number' => 'MWO-FIX', 'maintenance_type' => 'corrective', 'scheduled_date' => '2027-01-08', 'downtime_hours' => 3]);
    MaintenanceWorkOrder::factory()->create(['tenant_id' => $this->tenant->id, 'asset_id' => $asset->id, 'work_order_number' => 'MWO-DONE', 'status' => 'completed', 'scheduled_date' => '2027-01-08', 'downtime_hours' => 1]);

    app(MaterialiseShiftInstancesAction::class)((string) $this->tenant->id, null, CarbonImmutable::parse('2027-01-08 12:00', 'UTC'));

    $maintenance = collect(mesHoles('2027-01-08 05:00:00'))->where('kind', 'planned_maintenance')->values()->all();

    // Starts with the shift (06:00 local = 05:00 UTC) and lasts the two planned hours.
    expect($maintenance)->toBe([['kind' => 'planned_maintenance', 'machine_id' => (string) $this->machine->id, 'from' => '2027-01-08T05:00:00Z', 'to' => '2027-01-08T07:00:00Z', 'reference' => 'MWO-PLAN']])
        ->and(collect(mesHoles('2027-01-08 05:00:00'))->pluck('machine_id')->contains((string) $other->id))->toBeFalse();
});

test('a change of the holes is a change of the shift instance for the MES Core, an identical run changes nothing', function (): void {
    $today = CarbonImmutable::parse('2027-01-08 12:00', 'UTC');
    $action = app(MaterialiseShiftInstancesAction::class);

    $action((string) $this->tenant->id, null, $today);
    $seqs = DB::table('mfg_mes_sync_changes')->where('resource', 'shift-calendars')->count();

    expect($action((string) $this->tenant->id, null, $today))->toBe(['created' => 0, 'updated' => 0, 'deleted' => 0]);

    config(['manufacturing_mes.shifts.breaks' => [['start' => '11:00', 'minutes' => 20]]]);
    $result = $action((string) $this->tenant->id, null, $today);

    expect($result['updated'])->toBe(1)
        ->and(DB::table('mfg_mes_sync_changes')->where('resource', 'shift-calendars')->count())->toBe($seqs + 1)
        ->and(mesHoles('2027-01-08 05:00:00')[0]['from'])->toBe('2027-01-08T10:00:00Z');
});
