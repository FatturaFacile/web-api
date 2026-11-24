<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgBomComponent;
use Modules\Manufacturing\Models\MfgMachineCenter;
use Modules\Manufacturing\Models\MfgOperation;
use Modules\Manufacturing\Models\MfgRouting;
use Modules\Manufacturing\Models\MfgWorkCenter;
use Modules\Manufacturing\Models\MfgWorkingCalendar;
use Modules\Manufacturing\Services\ProductionEngineering\WorkingCalendarResolver;

require_once __DIR__.'/ManufacturingSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    mfgSetUp($this);
});

/**
 * Creates a working calendar through the API.
 *
 * @param  list<array<string, mixed>>  $shifts
 * @param  array<string, mixed>  $overrides
 */
function peCalendar(object $test, string $code, string $country, ?string $region, array $shifts, array $overrides = []): string
{
    return (string) $test->postJson('/api/v1/manufacturing/working-calendars', ['code' => $code, 'name' => $code, 'timezone' => $country === 'CH' ? 'Europe/Zurich' : 'Europe/Rome', 'country_code' => $country, 'region_code' => $region, 'shifts' => $shifts, ...$overrides])
        ->assertCreated()->json('data.id');
}

/**
 * Monday to Friday day shift 06:00-14:00 (no break: 480 net minutes).
 *
 * @return list<array<string, mixed>>
 */
function peWeekdayShifts(): array
{
    return array_map(static fn (int $day): array => ['day_of_week' => $day, 'shift_code' => 'DAY', 'start_time' => '06:00', 'end_time' => '14:00', 'break_minutes' => 0], [1, 2, 3, 4, 5]);
}

/**
 * Updates the rates and times of a work centre created by the helper of the support file.
 *
 * @param  array<string, mixed>  $attributes
 */
function peWorkCenter(object $test, string $code, array $attributes): MfgWorkCenter
{
    $workCenter = mfgWorkCenter($test, $code, '0', '0');
    $workCenter->update($attributes);

    return $workCenter->refresh();
}

test('a working calendar resolves shifts, weekends, Swiss cantonal and Italian holidays and planned shutdowns', function (): void {
    $ticino = peCalendar($this, 'TI-CAL', 'CH', 'TI', peWeekdayShifts(), ['closures' => [['starts_on' => '2027-01-11', 'ends_on' => '2027-01-12', 'reason' => 'Revision']]]);
    $zurich = peCalendar($this, 'ZH-CAL', 'CH', 'ZH', peWeekdayShifts());
    $rome = peCalendar($this, 'RM-CAL', 'IT', 'RM', peWeekdayShifts());

    $days = static fn (array $payload): array => collect($payload['data'])->keyBy('date')->all();

    $ti = $days($this->getJson("/api/v1/manufacturing/working-calendars/{$ticino}/preview-days?from=2027-01-01&to=2027-01-12")->assertOk()->json());
    expect($ti['2027-01-01']['day_type'])->toBe('holiday')
        ->and($ti['2027-01-02']['day_type'])->toBe('non_working_weekend')
        ->and($ti['2027-01-04']['day_type'])->toBe('working_shift')
        ->and($ti['2027-01-04']['available_minutes'])->toBe(480)
        ->and($ti['2027-01-06']['day_type'])->toBe('holiday')
        ->and($ti['2027-01-06']['label'])->toBe('Epifania')
        ->and($ti['2027-01-11']['day_type'])->toBe('planned_shutdown')
        ->and($ti['2027-01-11']['label'])->toBe('Revision')
        ->and($ti['2027-01-12']['available_minutes'])->toBe(0);

    $zh = $days($this->getJson("/api/v1/manufacturing/working-calendars/{$zurich}/preview-days?from=2027-01-01&to=2027-01-06")->assertOk()->json());
    expect($zh['2027-01-06']['day_type'])->toBe('working_shift')
        ->and($zh['2027-01-02']['label'])->toBe('San Bertoldo');

    $it = $days($this->getJson("/api/v1/manufacturing/working-calendars/{$rome}/preview-days?from=2027-03-29&to=2027-06-29")->assertOk()->json());
    expect($it['2027-03-29']['day_type'])->toBe('holiday')
        ->and($it['2027-03-29']['label'])->toBe('Lunedì dell\'Angelo')
        ->and($it['2027-06-29']['label'])->toBe('Santi Pietro e Paolo')
        ->and($it['2027-06-02']['day_type'])->toBe('holiday');
});

test('a calendar derives net minutes, flags a shift across midnight and projects working time over it', function (): void {
    $id = peCalendar($this, 'NIGHT', 'CH', 'ZH', [
        ['day_of_week' => 1, 'shift_code' => 'NIGHT', 'start_time' => '22:00', 'end_time' => '06:00', 'break_minutes' => 30],
    ]);

    $shift = $this->getJson("/api/v1/manufacturing/working-calendars/{$id}")->assertOk()->json('data.shifts.0');
    expect($shift['crosses_midnight'])->toBeTrue()->and($shift['net_available_minutes'])->toBe(450);

    // The Monday night shift runs into Tuesday 06:00; Tuesday has no shift of its own.
    $preview = $this->getJson("/api/v1/manufacturing/working-calendars/{$id}/preview-days?from=2027-01-04&to=2027-01-05")->assertOk()->json('data');
    expect($preview[0]['shifts'][0]['end_at'])->toStartWith('2027-01-05T06:00:00');

    $calendar = MfgWorkingCalendar::query()->findOrFail($id);
    $resolver = app(WorkingCalendarResolver::class);
    $from = CarbonImmutable::parse('2027-01-04T22:00:00', 'Europe/Zurich');

    // 450 net minutes are spread over 480 clock minutes: 60 net minutes take 64 clock minutes.
    expect($resolver->advance($calendar, $from, 60.0)->format('H:i'))->toBe('23:04')
        ->and($resolver->availableMinutes($calendar, $from, $from->addHours(8)))->toEqual(450.0)
        ->and($resolver->availableMinutes($calendar, CarbonImmutable::parse('2027-01-04T23:00:00', 'Europe/Zurich'), CarbonImmutable::parse('2027-01-05T01:00:00', 'Europe/Zurich')))->toBe(112.5);
});

test('lead time models efficiency, parallel capacity, queue, cooling and move times, and the transfer batch overlap', function (): void {
    $item = mfgItem($this, 'FG-LT', 'finished_good');
    $a = peWorkCenter($this, 'WC-A', ['parallel_capacity_units' => 2, 'efficiency_rate' => '50', 'hourly_setup_cost_rate' => '60', 'hourly_machine_run_cost_rate' => '120', 'hourly_cost_rate' => '30', 'hourly_overhead_rate' => '12', 'cost_center_id' => (string) Str::uuid()]);
    $b = peWorkCenter($this, 'WC-B', ['hourly_setup_cost_rate' => '48', 'hourly_machine_run_cost_rate' => '90', 'hourly_cost_rate' => '24', 'hourly_overhead_rate' => '6', 'cost_center_id' => (string) Str::uuid()]);

    $id = (string) $this->postJson('/api/v1/manufacturing/routings', ['mfg_item_id' => $item->id, 'name' => 'Pump', 'operations' => [
        ['operation_code' => 'TURN', 'name' => 'Turning', 'work_center_id' => $a->id, 'setup_time_minutes' => '30', 'run_time_minutes_per_unit' => '1.2', 'queue_time_minutes' => '10', 'post_operation_wait_minutes' => '5', 'move_time_minutes' => '5', 'overlap_transfer_batch_qty' => '20', 'required_operators' => 2, 'operation_type' => 'internal_machining'],
        ['operation_code' => 'ASSY', 'name' => 'Assembly', 'work_center_id' => $b->id, 'setup_time_minutes' => '10', 'run_time_minutes_per_unit' => '0.5', 'operation_type' => 'manual_assembly'],
    ]])->assertCreated()->assertJsonPath('data.operations.0.sequence', 10)->assertJsonPath('data.operations.1.sequence', 20)->json('data.id');

    $result = $this->postJson("/api/v1/manufacturing/routings/{$id}/calculate-lead-time-and-cost", ['quantity' => '100'])->assertOk()->json('data');

    // Op 10: 30 setup + 1.2 x 100 / (2 x 0.5) = 120 run + 10 queue + 5 cooling + 5 move = 170. Op 20: 10 + 50 = 60.
    expect($result['steps'][0]['run_minutes'])->toBe('120.000')
        ->and($result['steps'][0]['duration_minutes'])->toBe('170.000')
        ->and($result['lead_time']['serial_minutes'])->toBe('230.000')
        // Op 20 starts after the first 20 pieces of op 10 (10 + 30 + 24 + 5 + 5 = 74) but cannot end before the last
        // batch has been processed (170 + 10).
        ->and($result['steps'][1]['start_offset_minutes'])->toBe('74.000')
        ->and($result['lead_time']['total_minutes'])->toBe('180.000')
        ->and($result['lead_time']['overlap_saving_minutes'])->toBe('50.000')
        ->and($result['steps'][0]['overlap_applied'])->toBeTrue()
        ->and($result['lead_time']['calendar'])->toBeNull();

    $serial = $this->postJson("/api/v1/manufacturing/routings/{$id}/calculate-lead-time-and-cost", ['quantity' => '100', 'include_overlap' => false])->assertOk()->json('data');
    expect($serial['lead_time']['total_minutes'])->toBe('230.000')->and($serial['lead_time']['overlap_saving_minutes'])->toBe('0.000');

    // Batch not smaller than the lot: no overlap.
    MfgOperation::query()->where('operation_code', 'TURN')->update(['overlap_transfer_batch_qty' => '100']);
    expect($this->postJson("/api/v1/manufacturing/routings/{$id}/calculate-lead-time-and-cost", ['quantity' => '100'])->json('data.lead_time.total_minutes'))->toBe('230.000');
});

test('standard cost splits setup, machine, labour and overhead per step and cost centre, and prices subcontracting', function (): void {
    $item = mfgItem($this, 'FG-COST', 'finished_good');
    $centerA = (string) Str::uuid();
    $centerB = (string) Str::uuid();
    $a = peWorkCenter($this, 'WC-A', ['parallel_capacity_units' => 2, 'efficiency_rate' => '50', 'hourly_setup_cost_rate' => '60', 'hourly_machine_run_cost_rate' => '120', 'hourly_cost_rate' => '30', 'hourly_overhead_rate' => '12', 'cost_center_id' => $centerA]);
    $b = peWorkCenter($this, 'WC-B', ['hourly_setup_cost_rate' => '48', 'hourly_machine_run_cost_rate' => '90', 'hourly_cost_rate' => '24', 'hourly_overhead_rate' => '6', 'cost_center_id' => $centerB]);
    $machine = MfgMachineCenter::create(['tenant_id' => $this->tenant->id, 'machine_code' => 'M-1', 'name' => 'Lathe', 'work_center_id' => $b->id, 'hourly_machine_run_cost_rate_override' => '60', 'status' => 'active']);

    $id = (string) $this->postJson('/api/v1/manufacturing/routings', ['mfg_item_id' => $item->id, 'name' => 'Costed', 'operations' => [
        ['operation_code' => 'TURN', 'name' => 'Turning', 'work_center_id' => $a->id, 'setup_time_minutes' => '30', 'run_time_minutes_per_unit' => '1.2', 'required_operators' => 2],
        ['operation_code' => 'MILL', 'name' => 'Milling', 'work_center_id' => $b->id, 'machine_center_id' => $machine->id, 'setup_time_minutes' => '10', 'run_time_minutes_per_unit' => '0.5'],
        ['operation_code' => 'PLATE', 'name' => 'Plating', 'work_center_id' => $b->id, 'operation_type' => 'external_subcontracting', 'subcontract_unit_cost' => '2.5', 'run_time_minutes_per_unit' => '0'],
    ]])->assertCreated()->json('data.id');

    $cost = $this->postJson("/api/v1/manufacturing/routings/{$id}/calculate-lead-time-and-cost", ['quantity' => '100'])->assertOk()->json('data.cost');

    // Turning: setup 30/60 x 60 = 30; machine 120/60 x 120 = 240; labour 120/60 x 30 x 2 = 120; overhead (30 + 120)/60 x 12 = 30.
    // Milling (machine rate override 60): setup 8; machine 50/60 x 60 = 50; labour 50/60 x 24 = 20; overhead 60/60 x 6 = 6.
    // Plating: 100 x 2.5 = 250.
    expect($cost['setup'])->toBe('38.0000')
        ->and($cost['machine_run'])->toBe('290.0000')
        ->and($cost['direct_labor'])->toBe('140.0000')
        ->and($cost['overhead'])->toBe('36.0000')
        ->and($cost['subcontracting'])->toBe('250.0000')
        ->and($cost['total'])->toBe('754.0000')
        ->and($cost['unit'])->toBe('7.5400');

    $byCenter = collect($cost['by_cost_center'])->pluck('total', 'cost_center_id')->all();
    expect($byCenter[$centerA])->toBe('420.0000')->and($byCenter[$centerB])->toBe('334.0000');
});

test('lead time over a work centre calendar skips weekends and the cantonal holiday of the region', function (): void {
    $item = mfgItem($this, 'FG-CAL', 'finished_good');
    $ticino = peCalendar($this, 'TI', 'CH', 'TI', peWeekdayShifts());
    $zurich = peCalendar($this, 'ZH', 'CH', 'ZH', peWeekdayShifts());

    $projection = function (string $calendarId, string $code) use ($item): array {
        $center = peWorkCenter($this, $code, ['working_calendar_id' => $calendarId]);
        $id = (string) $this->postJson('/api/v1/manufacturing/routings', ['mfg_item_id' => $item->id, 'revision' => $code, 'name' => $code, 'operations' => [
            ['operation_code' => 'RUN', 'name' => 'Run', 'work_center_id' => $center->id, 'run_time_minutes_per_unit' => '10'],
        ]])->assertCreated()->json('data.id');

        return $this->postJson("/api/v1/manufacturing/routings/{$id}/calculate-lead-time-and-cost", ['quantity' => '100', 'start_datetime' => '2027-01-04T06:00:00+01:00'])->assertOk()->json('data.lead_time.calendar');
    };

    // 1000 working minutes: Monday 480, Tuesday 480, then 40 on the next working day.
    $zh = $projection($zurich, 'WC-ZH');
    expect($zh['finish_at'])->toBe('2027-01-06T06:40:00+01:00')->and($zh['elapsed_minutes'])->toBe('2920.000');

    // 6 January is a holiday in Ticino (Epifania): the remaining 40 minutes move to Thursday.
    $ti = $projection($ticino, 'WC-TI');
    expect($ti['finish_at'])->toBe('2027-01-07T06:40:00+01:00');
});

test('OEE multiplies availability, performance and quality and compares them with the target', function (): void {
    $calendar = peCalendar($this, 'OEE', 'CH', 'ZH', peWeekdayShifts());
    $center = peWorkCenter($this, 'WC-OEE', ['working_calendar_id' => $calendar, 'oee_target_percent' => '80']);
    $machine = (string) $this->postJson('/api/v1/manufacturing/machines', ['machine_code' => 'M-OEE', 'name' => 'Press', 'work_center_id' => $center->id, 'ideal_cycle_time_seconds_per_unit' => '30', 'is_industria_40_enabled' => true, 'telemetry_endpoint_key' => 'secret-key'])->assertCreated()->assertJsonMissingPath('data.telemetry_endpoint_key')->json('data.id');

    $this->postJson('/api/v1/manufacturing/oee/logs', ['work_center_id' => $center->id, 'machine_center_id' => $machine, 'period_start' => '2027-01-04T08:00:00+01:00', 'period_end' => '2027-01-04T12:00:00+01:00', 'planned_downtime_minutes' => '30', 'unplanned_downtime_minutes' => '45', 'loss_category' => 'equipment_failure', 'good_units' => '700', 'scrap_units' => '50', 'rework_units' => '50'])->assertCreated();

    $query = ['work_center_id' => $center->id, 'from_datetime' => '2027-01-04T00:00:00+01:00', 'to_datetime' => '2027-01-05T00:00:00+01:00'];
    $summary = $this->getJson('/api/v1/manufacturing/oee/summary?'.http_build_query($query))->assertOk()->json('data');

    // Planned 480 - 30 = 450; operating 450 - 45 = 405; availability 0.9; performance 800 x 0.5 / 405; quality 700 / 800.
    expect($summary['summary']['planned_production_minutes'])->toEqual(450.0)
        ->and($summary['summary']['operating_minutes'])->toEqual(405.0)
        ->and($summary['summary']['availability'])->toBe(0.9)
        ->and($summary['summary']['performance'])->toBe(0.987654)
        ->and($summary['summary']['quality'])->toBe(0.875)
        ->and($summary['summary']['oee'])->toBe(0.777778)
        ->and($summary['summary']['oee_percent'])->toBe(77.78)
        ->and($summary['meets_target'])->toBeFalse()
        ->and($summary['gap_to_target_percent'])->toBe(-2.22)
        ->and($summary['six_big_losses']['equipment_failure']['minutes'])->toEqual(45.0)
        ->and($summary['six_big_losses']['process_defects']['minutes'])->toEqual(50.0)
        ->and($summary)->not->toHaveKey('series')
        ->and($summary['machines'][0]['industria_40'])->toBeTrue()->and($summary['machines'][0]['telemetry_status'])->toBe('never');

    $series = $this->getJson('/api/v1/manufacturing/oee/timeseries?'.http_build_query([...$query, 'to_datetime' => '2027-01-06T00:00:00+01:00', 'granularity' => 'daily']))->assertOk()->json('data.series');
    expect($series)->toHaveCount(2)->and($series[0]['oee_percent'])->toBe(77.78)->and($series[1]['oee_percent'])->toEqual(0.0)->and($series[1]['quality'])->toEqual(1.0);

    $shifts = $this->getJson('/api/v1/manufacturing/oee/timeseries?'.http_build_query([...$query, 'to_datetime' => '2027-01-06T00:00:00+01:00', 'granularity' => 'shift']))->assertOk()->json('data.series');
    expect($shifts)->toHaveCount(2)->and($shifts[0]['scheduled_minutes'])->toEqual(480.0);
});

test('OEE is zero without planned production time or operating time and never exceeds one', function (): void {
    $calendar = peCalendar($this, 'OEE-Z', 'CH', 'TI', peWeekdayShifts());
    $center = peWorkCenter($this, 'WC-Z', ['working_calendar_id' => $calendar]);

    // 1 January is a public holiday: nothing is scheduled.
    $holiday = $this->getJson('/api/v1/manufacturing/oee/summary?'.http_build_query(['work_center_id' => $center->id, 'from_datetime' => '2027-01-01T00:00:00+01:00', 'to_datetime' => '2027-01-02T00:00:00+01:00']))->assertOk()->json('data.summary');
    expect($holiday['planned_production_minutes'])->toEqual(0.0)->and($holiday['availability'])->toEqual(0.0)->and($holiday['oee'])->toEqual(0.0)->and($holiday['quality'])->toEqual(1.0);

    // Unplanned downtime equal to the planned time: no operating time, so performance is zero.
    $this->postJson('/api/v1/manufacturing/oee/logs', ['work_center_id' => $center->id, 'period_start' => '2027-01-04T08:00:00+01:00', 'period_end' => '2027-01-04T12:00:00+01:00', 'unplanned_downtime_minutes' => '600', 'good_units' => '10'])->assertCreated();
    $down = $this->getJson('/api/v1/manufacturing/oee/summary?'.http_build_query(['work_center_id' => $center->id, 'from_datetime' => '2027-01-04T00:00:00+01:00', 'to_datetime' => '2027-01-05T00:00:00+01:00']))->assertOk()->json('data.summary');
    expect($down['operating_minutes'])->toEqual(0.0)->and($down['availability'])->toEqual(0.0)->and($down['performance'])->toEqual(0.0)->and($down['oee'])->toEqual(0.0);

    // More output than the ideal cycle allows is clamped to a performance of one.
    $this->postJson('/api/v1/manufacturing/oee/logs', ['work_center_id' => $center->id, 'period_start' => '2027-01-05T08:00:00+01:00', 'period_end' => '2027-01-05T12:00:00+01:00', 'good_units' => '100000'])->assertCreated();
    $item = mfgItem($this, 'FG-Z', 'finished_good');
    $this->postJson('/api/v1/manufacturing/routings', ['mfg_item_id' => $item->id, 'name' => 'Z', 'operations' => [['operation_code' => 'RUN', 'name' => 'Run', 'work_center_id' => $center->id, 'run_time_minutes_per_unit' => '1']]])->assertCreated();
    $clamped = $this->getJson('/api/v1/manufacturing/oee/summary?'.http_build_query(['work_center_id' => $center->id, 'from_datetime' => '2027-01-05T00:00:00+01:00', 'to_datetime' => '2027-01-06T00:00:00+01:00']))->assertOk()->json('data.summary');
    expect($clamped['ideal_cycle_minutes_per_unit'])->toEqual(1.0)->and($clamped['performance'])->toEqual(1.0)->and($clamped['oee'])->toEqual(1.0);
});

test('machines take telemetry pings only with the right key and record the counters as a telemetry log', function (): void {
    $center = peWorkCenter($this, 'WC-T', []);
    $plain = $this->postJson('/api/v1/manufacturing/machines', ['machine_code' => 'M-PLAIN', 'name' => 'Plain', 'work_center_id' => $center->id])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/manufacturing/machines/{$plain}/telemetry-ping", ['telemetry_endpoint_key' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['machine']);

    $id = (string) $this->postJson('/api/v1/manufacturing/machines', ['machine_code' => 'M-4', 'name' => 'CNC', 'work_center_id' => $center->id, 'is_transizione_50_enabled' => true, 'telemetry_device_id' => 'dev-1', 'telemetry_endpoint_key' => 'k-123'])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/manufacturing/machines/{$id}/telemetry-ping", ['telemetry_endpoint_key' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors(['telemetry_endpoint_key']);
    $this->postJson("/api/v1/manufacturing/machines/{$id}/telemetry-ping", ['telemetry_endpoint_key' => 'k-123', 'status' => 'online', 'period_start' => '2027-01-04T08:00:00+01:00', 'period_end' => '2027-01-04T09:00:00+01:00', 'good_units' => '40', 'scrap_units' => '2'])
        ->assertOk()->assertJsonPath('data.telemetry_status', 'online');

    expect(MfgMachineCenter::query()->findOrFail($id)->telemetry_last_seen_at)->not->toBeNull();
    $this->assertDatabaseHas('mfg_oee_logs', ['machine_center_id' => $id, 'source' => 'telemetry']);
});

test('work centres keep a hierarchy and alternates without cycles and stay inside the company', function (): void {
    $calendar = peCalendar($this, 'WC-CAL', 'CH', 'TI', peWeekdayShifts());
    $department = (string) $this->postJson('/api/v1/manufacturing/work-centers', ['work_center_code' => 'DEP', 'name' => 'Department', 'working_calendar_id' => $calendar, 'parallel_capacity_units' => 2, 'oee_target_percent' => '85', 'hourly_setup_cost_rate' => '55.5', 'currency_code' => 'CHF'])
        ->assertCreated()->assertJsonPath('data.parallel_capacity_units', 2)->assertJsonPath('data.oee_target_percent', '85.00')->assertJsonPath('data.hourly_setup_cost_rate', '55.5000')->assertJsonPath('data.currency_code', 'CHF')->json('data.id');
    $cell = (string) $this->postJson('/api/v1/manufacturing/work-centers', ['work_center_code' => 'CELL', 'name' => 'Cell', 'parent_work_center_id' => $department])->assertCreated()->json('data.id');
    $this->postJson('/api/v1/manufacturing/machines', ['machine_code' => 'M-CELL', 'name' => 'Cell machine', 'work_center_id' => $cell])->assertCreated();

    $tree = $this->getJson("/api/v1/manufacturing/work-centers/{$department}/hierarchy")->assertOk()->json('data');
    expect($tree['children'][0]['work_center_code'])->toBe('CELL')->and($tree['children'][0]['machines'][0]['machine_code'])->toBe('M-CELL');

    $this->putJson("/api/v1/manufacturing/work-centers/{$department}", ['parent_work_center_id' => $cell])->assertUnprocessable();
    $this->putJson("/api/v1/manufacturing/work-centers/{$cell}", ['alternate_work_center_ids' => [$cell]])->assertUnprocessable();
    $this->putJson("/api/v1/manufacturing/work-centers/{$cell}", ['alternate_work_center_ids' => [$department]])->assertOk()->assertJsonPath('data.alternate_work_center_ids.0', $department);
    $this->putJson("/api/v1/manufacturing/work-centers/{$department}", ['alternate_work_center_ids' => [$cell]])->assertUnprocessable();

    $this->putJson("/api/v1/manufacturing/work-centers/{$cell}", ['efficiency_rate' => '250', 'parallel_capacity_units' => 0, 'hourly_setup_cost_rate' => '-1'])->assertUnprocessable()->assertJsonValidationErrors(['efficiency_rate', 'parallel_capacity_units', 'hourly_setup_cost_rate']);

    $foreign = mfgWorkCenter($this, 'FOREIGN', '1', '1', $this->otherTenant);
    $this->postJson('/api/v1/manufacturing/work-centers', ['work_center_code' => 'BAD', 'name' => 'Bad', 'parent_work_center_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors(['parent_work_center_id']);
    $this->getJson("/api/v1/manufacturing/work-centers/{$foreign->id}/hierarchy")->assertNotFound();
    $this->getJson('/api/v1/manufacturing/oee/summary?'.http_build_query(['work_center_id' => $foreign->id, 'from_datetime' => '2027-01-04T00:00:00+01:00', 'to_datetime' => '2027-01-05T00:00:00+01:00']))->assertUnprocessable();

    // A work centre with sub work centres cannot be deleted (409); a leaf can; a calendar in use cannot.
    $this->deleteJson("/api/v1/manufacturing/work-centers/{$department}")->assertStatus(409);
    $this->deleteJson("/api/v1/manufacturing/working-calendars/{$calendar}")->assertStatus(409);
    $this->deleteJson("/api/v1/manufacturing/work-centers/{$foreign->id}")->assertNotFound();
});

test('routings validate sequence order, machine and component links, are redefined and cloned into the next revision', function (): void {
    $fg = mfgItem($this, 'FG-RT', 'finished_good');
    $steel = mfgItem($this, 'RM-STEEL', 'raw_material', '4.00');
    $other = mfgItem($this, 'FG-OTHER', 'finished_good');
    $center = peWorkCenter($this, 'WC-RT', []);
    $otherCenter = peWorkCenter($this, 'WC-OTHER', []);
    $machine = MfgMachineCenter::create(['tenant_id' => $this->tenant->id, 'machine_code' => 'M-RT', 'name' => 'Machine', 'work_center_id' => $otherCenter->id, 'status' => 'active']);

    $bom = (string) $this->postJson('/api/v1/manufacturing/boms', ['mfg_item_id' => $fg->id, 'name' => 'BOM', 'components' => [['component_item_id' => $steel->id, 'quantity_per' => '2', 'scrap_percentage' => '10']]])->assertCreated()->json('data.id');
    $componentId = (string) MfgBomComponent::query()->where('bom_id', $bom)->value('id');
    $foreignBom = (string) $this->postJson('/api/v1/manufacturing/boms', ['mfg_item_id' => $other->id, 'name' => 'Other BOM', 'components' => [['component_item_id' => $steel->id, 'quantity_per' => '1']]])->assertCreated()->json('data.id');
    $foreignComponent = (string) MfgBomComponent::query()->where('bom_id', $foreignBom)->value('id');

    $operation = static fn (array $extra = []): array => ['operation_code' => 'OP', 'name' => 'Op', 'work_center_id' => $center->id, 'run_time_minutes_per_unit' => '1', ...$extra];

    $this->postJson('/api/v1/manufacturing/routings', ['mfg_item_id' => $fg->id, 'name' => 'R', 'operations' => [$operation(['sequence' => 20]), $operation(['sequence' => 10])]])->assertUnprocessable()->assertJsonValidationErrors(['operations']);
    $this->postJson('/api/v1/manufacturing/routings', ['mfg_item_id' => $fg->id, 'name' => 'R', 'operations' => [$operation(['machine_center_id' => $machine->id])]])->assertUnprocessable();
    $this->postJson('/api/v1/manufacturing/routings', ['mfg_item_id' => $fg->id, 'name' => 'R', 'operations' => [$operation(['operation_type' => 'external_subcontracting'])]])->assertUnprocessable();
    $this->postJson('/api/v1/manufacturing/routings', ['mfg_item_id' => $fg->id, 'name' => 'R', 'operations' => [$operation(['bom_components' => [['bom_component_id' => $componentId]]])]])->assertUnprocessable();
    $this->postJson('/api/v1/manufacturing/routings', ['mfg_item_id' => $fg->id, 'bom_id' => $bom, 'name' => 'R', 'operations' => [$operation(['bom_components' => [['bom_component_id' => $foreignComponent]]])]])->assertUnprocessable();
    $this->postJson('/api/v1/manufacturing/routings', ['mfg_item_id' => $fg->id, 'name' => 'R', 'operations' => [$operation(['alternate_work_center_ids' => [$center->id]])]])->assertUnprocessable();
    expect(MfgRouting::query()->count())->toBe(0);

    $id = (string) $this->postJson('/api/v1/manufacturing/routings', ['mfg_item_id' => $fg->id, 'bom_id' => $bom, 'name' => 'R', 'operations' => [
        $operation(['sequence' => 10, 'work_instruction_doc_id' => null, 'bom_components' => [['bom_component_id' => $componentId, 'backflush_on_completion' => false, 'notes' => 'Bar stock']], 'alternate_work_center_ids' => [$otherCenter->id]]),
        $operation(['operation_code' => 'OP2', 'sequence' => 25]),
    ]])->assertCreated()->assertJsonPath('data.bom_id', $bom)->assertJsonPath('data.operations.0.bom_components.0.backflush_on_completion', false)->assertJsonPath('data.operations.0.alternate_work_center_ids.0', $otherCenter->id)->json('data.id');

    // Material cost of the linked component: 2 x 1.10 x 4.00 x 10 = 88.
    $this->postJson("/api/v1/manufacturing/routings/{$id}/calculate-lead-time-and-cost", ['quantity' => '10'])->assertOk()->assertJsonPath('data.cost.material', '88.0000');

    $this->putJson("/api/v1/manufacturing/routings/{$id}", ['name' => 'R2', 'operations' => [$operation(['operation_code' => 'ONLY'])]])->assertOk()->assertJsonPath('data.name', 'R2')->assertJsonCount(1, 'data.operations');
    $this->assertDatabaseCount('mfg_routing_step_bom_components', 0);

    $clone = $this->postJson("/api/v1/manufacturing/routings/{$id}/clone-revision")->assertCreated()->assertJsonPath('data.revision', 'B')->assertJsonPath('data.status', 'draft')->assertJsonPath('data.is_default', false)->assertJsonCount(1, 'data.operations')->json('data.id');
    $this->postJson("/api/v1/manufacturing/routings/{$id}/clone-revision", ['revision' => 'B'])->assertUnprocessable()->assertJsonValidationErrors(['revision']);
    $this->postJson("/api/v1/manufacturing/routings/{$clone}/clone-revision")->assertCreated()->assertJsonPath('data.revision', 'C');

    $this->deleteJson("/api/v1/manufacturing/routings/{$clone}")->assertNoContent();
    $this->getJson("/api/v1/manufacturing/routings/{$clone}")->assertNotFound();

    mfgActAs($this, $this->reader);
    $this->putJson("/api/v1/manufacturing/routings/{$id}", ['name' => 'Nope'])->assertForbidden();
});

test('revision labels advance by letter or number', function (): void {
    $item = mfgItem($this, 'FG-REV', 'finished_good');
    $center = peWorkCenter($this, 'WC-REV', []);
    $id = (string) $this->postJson('/api/v1/manufacturing/routings', ['mfg_item_id' => $item->id, 'revision' => '1.0', 'name' => 'Numbered', 'operations' => [['operation_code' => 'OP', 'name' => 'Op', 'work_center_id' => $center->id]]])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/manufacturing/routings/{$id}/clone-revision")->assertCreated()->assertJsonPath('data.revision', '2.0');
});
