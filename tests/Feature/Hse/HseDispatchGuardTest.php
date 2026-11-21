<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Hse\Domain\Contracts\HseDispatchGuardInterface;
use Modules\Hse\Domain\Enums\CertificateType;
use Modules\Hse\Domain\Enums\ClearanceContext;

require_once __DIR__.'/HseTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    DB::statement('PRAGMA defer_foreign_keys = ON');
    $this->tenant = hseTenant();
    $this->employee = hseEmployee($this->tenant, ['position_id' => null]);
    $this->employee->forceFill(['position_id' => null])->save();
    $this->target = (string) Str::uuid();
    hseMatrix($this->tenant, [
        'job_position_id' => null, 'work_center_id' => $this->target, 'construction_site_id' => null,
        'required_training_types_json' => [['type' => CertificateType::Forklift->value]],
    ]);
});

it('throws a validation error under the hse key when the worker is not cleared', function (): void {
    expect(fn () => app(HseDispatchGuardInterface::class)->assertCleared(
        (string) $this->tenant->id, (string) $this->employee->id, null, ClearanceContext::ProductionWorkCenter, $this->target,
    ))->toThrow(ValidationException::class);
});

it('clears the worker once the certificate is held', function (): void {
    hseCertificate($this->tenant, $this->employee, CertificateType::Forklift, '2026-01-01', '2031-01-01');

    $result = app(HseDispatchGuardInterface::class)->assertCleared(
        (string) $this->tenant->id, (string) $this->employee->id, null, ClearanceContext::ProductionWorkCenter, $this->target,
    );

    expect($result->isCleared)->toBeTrue();
});

it('lets through a user that is not an employee', function (): void {
    $result = app(HseDispatchGuardInterface::class)->assertCleared(
        (string) $this->tenant->id, null, (string) Str::uuid(), ClearanceContext::ExternalFieldService, $this->target,
    );

    expect($result->isCleared)->toBeTrue();
});
