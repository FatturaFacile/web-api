<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Attendance\AttendanceComplianceService;
use App\Services\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->complianceService = new AttendanceComplianceService;
    $this->tenant = Tenant::create([
        'name' => 'Test Tenant',
        'legal_name' => 'Test Tenant SRL',
        'country' => 'IT',
    ]);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::create([
        'name' => 'Mario Rossi',
        'email' => 'mario@example.com',
        'password' => bcrypt('password'),
    ]);
});

test('it flags italian 11 consecutive hours daily rest violation', function (): void {
    $day1 = Carbon::parse('2026-10-01');

    // Shift 1: 08:00 - 18:00
    TimeEntry::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
        'clock_in' => $day1->copy()->setHour(8),
        'clock_out' => $day1->copy()->setHour(18),
        'break_minutes' => 60,
    ]);

    // Shift 2 starts at 02:00 next day (only 8 hours rest between 18:00 and 02:00 -> Violation of 11h rest)
    TimeEntry::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
        'clock_in' => $day1->copy()->addDay()->setHour(2),
        'clock_out' => $day1->copy()->addDay()->setHour(10),
        'break_minutes' => 30,
    ]);

    $audit = $this->complianceService->checkItalianCompliance(
        $this->user,
        $day1,
        $day1->copy()->addDay()
    );

    expect($audit['compliant'])->toBeFalse()
        ->and($audit['violations'])->toHaveCount(1)
        ->and($audit['violations'][0]['rule'])->toBe('IT_D_LGS_66_2003_ART_7');
});

test('it passes when daily rest conforms to italian 11 hours minimum', function (): void {
    $day1 = Carbon::parse('2026-10-01');

    // Shift 1: 08:00 - 17:00
    TimeEntry::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
        'clock_in' => $day1->copy()->setHour(8),
        'clock_out' => $day1->copy()->setHour(17),
        'break_minutes' => 60,
    ]);

    // Shift 2 starts at 08:00 next day (15 hours rest -> compliant)
    TimeEntry::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
        'clock_in' => $day1->copy()->addDay()->setHour(8),
        'clock_out' => $day1->copy()->addDay()->setHour(17),
        'break_minutes' => 60,
    ]);

    $audit = $this->complianceService->checkItalianCompliance(
        $this->user,
        $day1,
        $day1->copy()->addDay()
    );

    expect($audit['compliant'])->toBeTrue()
        ->and($audit['violations'])->toBeEmpty();
});

test('it flags swiss arg mandatory break violations', function (): void {
    $date = Carbon::parse('2026-10-05');

    // 8.0 hours continuous work with only 10 minutes break (ArG requires min 30 min break for > 7 hours)
    TimeEntry::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
        'clock_in' => $date->copy()->setHour(8),
        'clock_out' => $date->copy()->setHour(16),
        'break_minutes' => 10,
    ]);

    $audit = $this->complianceService->checkSwissArGCompliance(
        $this->user,
        $date,
        $date
    );

    expect($audit['compliant'])->toBeFalse()
        ->and($audit['violations'][0]['rule'])->toBe('CH_ARG_ART_15_BREAK_30M');
});
