<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Hse\Domain\Enums\CertificateStatus;
use Modules\Hse\Domain\Enums\CertificateType;
use Modules\Hse\Domain\Enums\FitnessOutcome;
use Modules\Hse\Infrastructure\Models\EmployeeSafetyCertificate;
use Modules\Hse\Infrastructure\Models\HseExpirationAlert;
use Modules\Hse\Infrastructure\Models\PpeDistributionRecord;

require_once __DIR__.'/HseTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    DB::statement('PRAGMA defer_foreign_keys = ON');
    $this->tenant = hseTenant();
    $this->hr = hseActAs($this->tenant, 'hr');
    $this->hse = hseActAs($this->tenant, 'hse');
    $this->managerUser = hseActAs($this->tenant, 'member');
    $manager = hseEmployee($this->tenant, ['user_id' => $this->managerUser->id]);
    $this->employee = hseEmployee($this->tenant, ['manager_id' => $manager->id]);
});

function hseNotificationCount(string $activity): int
{
    return DB::table('notifications')->where('data', 'like', '%"activity":"'.$activity.'"%')->count();
}

it('alerts HR, the RSPP/SiBe and the line manager at exactly 60, 30 and 7 days before a certificate expires', function (int $days): void {
    $today = CarbonImmutable::parse('2027-03-01');
    hseCertificate($this->tenant, $this->employee, CertificateType::Forklift, '2024-01-01', $today->addDays($days)->toDateString());

    Artisan::call('hse:check-expirations', ['--date' => $today->toDateString()]);

    expect(HseExpirationAlert::query()->where('threshold_days', $days)->count())->toBe(1)
        ->and(hseNotificationCount('hse_deadline_expiring'))->toBe(3);
})->with([60, 30, 7]);

it('sends nothing on the days between the thresholds', function (): void {
    $today = CarbonImmutable::parse('2027-03-01');
    hseCertificate($this->tenant, $this->employee, CertificateType::Forklift, '2024-01-01', $today->addDays(45)->toDateString());

    Artisan::call('hse:check-expirations', ['--date' => $today->toDateString()]);

    expect(HseExpirationAlert::query()->count())->toBe(0)->and(hseNotificationCount('hse_deadline_expiring'))->toBe(0);
});

it('does not send the same threshold twice when the scan runs again', function (): void {
    $today = CarbonImmutable::parse('2027-03-01');
    hseCertificate($this->tenant, $this->employee, CertificateType::FirstAid, '2024-01-01', $today->addDays(30)->toDateString());

    Artisan::call('hse:check-expirations', ['--date' => $today->toDateString()]);
    Artisan::call('hse:check-expirations', ['--date' => $today->toDateString()]);

    expect(HseExpirationAlert::query()->count())->toBe(1)->and(hseNotificationCount('hse_deadline_expiring'))->toBe(3);
});

it('escalates every day once a certificate is overdue and marks it expired', function (): void {
    $certificate = hseCertificate($this->tenant, $this->employee, CertificateType::Forklift, '2020-01-01', '2027-02-27');

    Artisan::call('hse:check-expirations', ['--date' => '2027-03-01']);
    Artisan::call('hse:check-expirations', ['--date' => '2027-03-02']);

    expect(HseExpirationAlert::query()->where('threshold_days', -1)->count())->toBe(2)
        ->and(hseNotificationCount('hse_deadline_overdue'))->toBe(6)
        ->and($certificate->refresh()->status)->toBe(CertificateStatus::Expired);
});

it('alerts on a medical visit and a PPE replacement at the same thresholds', function (): void {
    $today = CarbonImmutable::parse('2027-03-01');
    hseMedicalVisit($this->tenant, $this->employee, FitnessOutcome::Fit, '2026-03-01', $today->addDays(60)->toDateString());
    PpeDistributionRecord::create([
        'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id, 'product_id' => (string) Str::uuid(), 'warehouse_id' => (string) Str::uuid(),
        'quantity' => 1, 'handed_over_at' => '2026-03-01 08:00:00', 'replacement_due_at' => $today->addDays(7)->toDateString(),
        'employee_signature_hash' => str_repeat('a', 64), 'delivery_status' => 'delivered_signed',
    ]);

    Artisan::call('hse:check-expirations', ['--date' => $today->toDateString()]);

    expect(HseExpirationAlert::query()->where('subject_type', 'medical_visit')->where('threshold_days', 60)->count())->toBe(1)
        ->and(HseExpirationAlert::query()->where('subject_type', 'ppe')->where('threshold_days', 7)->count())->toBe(1);
});

it('stops alerting on a certificate replaced by a newer one', function (): void {
    $today = CarbonImmutable::parse('2027-03-01');
    hseCertificate($this->tenant, $this->employee, CertificateType::Forklift, '2022-03-31', $today->addDays(30)->toDateString());
    hseCertificate($this->tenant, $this->employee, CertificateType::Forklift, '2027-02-15', '2032-02-15');

    Artisan::call('hse:check-expirations', ['--date' => $today->toDateString()]);

    expect(HseExpirationAlert::query()->count())->toBe(0);
});

it('keeps the alerts of one tenant away from another', function (): void {
    $other = hseTenant();
    $stranger = hseEmployee($other);
    hseCertificate($other, $stranger, CertificateType::FirstAid, '2024-01-01', '2027-03-31');

    Artisan::call('hse:check-expirations', ['--date' => '2027-03-01', '--tenant' => (string) $this->tenant->id]);

    expect(HseExpirationAlert::query()->withoutGlobalScopes()->count())->toBe(0);
    expect(EmployeeSafetyCertificate::query()->withoutGlobalScopes()->where('tenant_id', $other->id)->count())->toBe(1);
});
