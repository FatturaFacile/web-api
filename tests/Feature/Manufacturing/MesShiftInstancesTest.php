<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Manufacturing\Models\MfgShiftInstance;
use Modules\Manufacturing\Models\MfgWorkCenterCapacity;
use Modules\Manufacturing\Models\MfgWorkCenterShift;
use Modules\Manufacturing\Services\MesSync\MaterialiseShiftInstancesAction;

require_once __DIR__.'/MesTestSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    mesSetUp($this);
    config(['manufacturing_mes.shifts' => ['timezone' => 'Europe/Rome', 'start_time' => '06:00', 'code' => 'DAY', 'horizon_days' => 5]]);

    // Monday to Friday, eight hours.
    foreach ([1, 2, 3, 4, 5] as $weekday) {
        MfgWorkCenterShift::create(['tenant_id' => $this->tenant->id, 'work_center_id' => $this->workCenter->id, 'weekday' => $weekday, 'available_hours' => '8']);
    }
});

/**
 * Drops what the calendar observer materialised for today while the fixtures were written, so a test starts from nothing.
 */
function mesClearInstances(): void
{
    MfgShiftInstance::withTrashed()->forceDelete();
    DB::table('mfg_mes_sync_changes')->where('resource', 'shift-calendars')->delete();
}

/**
 * @return array<string, string> starts_at => ends_at in UTC
 */
function mesInstances(object $test): array
{
    return MfgShiftInstance::query()->where('scope_id', $test->workCenter->id)->where('starts_at', '>=', '2027-01-01')->orderBy('starts_at')->get()
        ->mapWithKeys(fn (MfgShiftInstance $row): array => [$row->starts_at->utc()->format('Y-m-d H:i') => $row->ends_at->utc()->format('Y-m-d H:i')])->all();
}

test('the weekly calendar becomes UTC intervals and weekends have none', function (): void {
    // Friday 2027-01-08 to Tuesday 2027-01-12, winter time (UTC+1): 06:00 local is 05:00 UTC.
    mesClearInstances();
    $result = app(MaterialiseShiftInstancesAction::class)((string) $this->tenant->id, null, CarbonImmutable::parse('2027-01-08 12:00', 'UTC'));

    expect($result)->toBe(['created' => 3, 'updated' => 0, 'deleted' => 0])
        ->and(mesInstances($this))->toBe([
            '2027-01-08 05:00' => '2027-01-08 13:00',
            '2027-01-11 05:00' => '2027-01-11 13:00',
            '2027-01-12 05:00' => '2027-01-12 13:00',
        ])
        ->and(DB::table('mfg_mes_sync_changes')->where('resource', 'shift-calendars')->count())->toBe(3);
});

test('a dated exception replaces the weekly hours and a zero exception removes the day', function (): void {
    MfgWorkCenterCapacity::factory()->create(['tenant_id' => $this->tenant->id, 'work_center_id' => $this->workCenter->id, 'calendar_date' => '2027-01-11', 'available_hours' => '4']);
    MfgWorkCenterCapacity::factory()->create(['tenant_id' => $this->tenant->id, 'work_center_id' => $this->workCenter->id, 'calendar_date' => '2027-01-12', 'available_hours' => '0']);
    MfgWorkCenterCapacity::factory()->create(['tenant_id' => $this->tenant->id, 'work_center_id' => $this->workCenter->id, 'calendar_date' => '2027-01-09', 'available_hours' => '6']);

    mesClearInstances();
    app(MaterialiseShiftInstancesAction::class)((string) $this->tenant->id, null, CarbonImmutable::parse('2027-01-08 12:00', 'UTC'));

    expect(mesInstances($this))->toBe([
        '2027-01-08 05:00' => '2027-01-08 13:00',
        '2027-01-09 05:00' => '2027-01-09 11:00',
        '2027-01-11 05:00' => '2027-01-11 09:00',
    ]);
});

test('daylight saving time moves the UTC start but not the local one', function (): void {
    config(['manufacturing_mes.shifts.horizon_days' => 5]);

    // Spring forward on Sunday 2027-03-28: Friday 26th is UTC+1 (05:00Z), Monday 29th is UTC+2 (04:00Z).
    mesClearInstances();
    app(MaterialiseShiftInstancesAction::class)((string) $this->tenant->id, null, CarbonImmutable::parse('2027-03-26 12:00', 'UTC'));
    expect(mesInstances($this))->toBe([
        '2027-03-26 05:00' => '2027-03-26 13:00',
        '2027-03-29 04:00' => '2027-03-29 12:00',
        '2027-03-30 04:00' => '2027-03-30 12:00',
    ]);

    // Fall back on Sunday 2027-10-31: Friday 29th is UTC+2 (04:00Z), Monday 1 November is UTC+1 (05:00Z).
    MfgShiftInstance::query()->delete();
    mesClearInstances();
    app(MaterialiseShiftInstancesAction::class)((string) $this->tenant->id, null, CarbonImmutable::parse('2027-10-29 12:00', 'UTC'));
    expect(mesInstances($this))->toBe([
        '2027-10-29 04:00' => '2027-10-29 12:00',
        '2027-11-01 05:00' => '2027-11-01 13:00',
        '2027-11-02 05:00' => '2027-11-02 13:00',
    ]);
});

test('rematerialising is idempotent, follows calendar changes and tombstones removed days', function (): void {
    $action = app(MaterialiseShiftInstancesAction::class);
    $today = CarbonImmutable::parse('2027-01-08 12:00', 'UTC');

    mesClearInstances();
    $action((string) $this->tenant->id, null, $today);
    $seqBefore = (int) DB::table('mfg_mes_sync_changes')->max('seq');

    expect($action((string) $this->tenant->id, null, $today))->toBe(['created' => 0, 'updated' => 0, 'deleted' => 0])
        ->and((int) DB::table('mfg_mes_sync_changes')->max('seq'))->toBe($seqBefore);

    MfgWorkCenterShift::query()->where('work_center_id', $this->workCenter->id)->where('weekday', 2)->update(['available_hours' => '6']);
    MfgWorkCenterShift::query()->where('work_center_id', $this->workCenter->id)->where('weekday', 1)->delete();

    expect($action((string) $this->tenant->id, null, $today))->toBe(['created' => 0, 'updated' => 1, 'deleted' => 1])
        ->and(mesInstances($this))->toBe(['2027-01-08 05:00' => '2027-01-08 13:00', '2027-01-12 05:00' => '2027-01-12 11:00'])
        ->and(MfgShiftInstance::onlyTrashed()->where('starts_at', '2027-01-11 05:00:00')->exists())->toBeTrue();
});

test('instances are served by the pull endpoint with their UTC interval', function (): void {
    mesClearInstances();
    app(MaterialiseShiftInstancesAction::class)((string) $this->tenant->id, null, CarbonImmutable::parse('2027-01-08 12:00', 'UTC'));

    $items = mesCall($this, 'GET', '/api/v1/manufacturing/mes-sync/shift-calendars')->assertOk()->json('items');

    expect($items)->toHaveCount(3)
        ->and($items[0]['data']['timezone'])->toBe('Europe/Rome')
        ->and(CarbonImmutable::parse($items[0]['data']['starts_at'])->utc()->format('Y-m-d H:i'))->toBe('2027-01-08 05:00');
});
