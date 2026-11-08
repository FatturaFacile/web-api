<?php

declare(strict_types=1);

use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Payroll\Exceptions\PayrollRunClosedException;
use Modules\Payroll\Models\PayrollInput;
use Modules\Payroll\Models\PayrollRun;
use Modules\Payroll\Models\PayrollRunEvent;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Models\PayslipLine;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-06 10:00:00');
    $this->tenant = payrollTenant();
    $this->manager = payrollMember($this->tenant, 'admin');
    $this->employee = payrollEmployee($this->tenant);
    payrollProfile($this->tenant, $this->employee);
    payrollSwissSetup($this->tenant, $this->manager);
    $this->run = payrollClosedRun($this->tenant, $this->manager, 2026, 1);
    $this->payslip = Payslip::query()->withoutGlobalScopes()->where('payroll_run_id', $this->run->id)->sole();
});

/**
 * The statement fails inside a savepoint, so that the transaction of the test survives.
 */
function payrollRejectedByDatabase(callable $statement): void
{
    expect(fn () => DB::transaction($statement))->toThrow(QueryException::class, 'immutable');
}

test('a closed run cannot be recalculated', function (): void {
    payrollCalculate($this->tenant, $this->manager, 2026, 1)
        ->assertConflict()
        ->assertJsonPath('detail', "L'elaborazione 01/2026 è chiusa: riaprirla per ricalcolarla.");
});

test('a closed run cannot be closed twice', function (): void {
    payrollApi($this->manager, $this->tenant)->postJson("/api/v1/payroll/runs/{$this->run->id}/finalize")->assertConflict();
});

test('the models refuse to change what belongs to a closed run', function (): void {
    $line = PayslipLine::query()->withoutGlobalScopes()->where('payslip_id', $this->payslip->id)->first();

    expect(fn () => $this->run->update(['payment_date' => '2026-02-01']))->toThrow(PayrollRunClosedException::class)
        ->and(fn () => $this->run->delete())->toThrow(PayrollRunClosedException::class)
        ->and(fn () => $this->payslip->update(['gross_salary' => '1.00']))->toThrow(PayrollRunClosedException::class)
        ->and(fn () => $this->payslip->delete())->toThrow(PayrollRunClosedException::class)
        ->and(fn () => $line->update(['amount' => '1.00']))->toThrow(PayrollRunClosedException::class)
        ->and(fn () => $line->delete())->toThrow(PayrollRunClosedException::class);
});

test('the database refuses to change a closed run even without the models', function (): void {
    payrollRejectedByDatabase(fn () => DB::table('payroll_runs')->where('id', $this->run->id)->update(['payment_date' => '2026-02-01']));
    payrollRejectedByDatabase(fn () => DB::table('payroll_runs')->where('id', $this->run->id)->update(['status' => 'Draft']));
    payrollRejectedByDatabase(fn () => DB::table('payroll_runs')->where('id', $this->run->id)->delete());
    payrollRejectedByDatabase(fn () => DB::table('payroll_payslips')->where('id', $this->payslip->id)->update(['gross_salary' => '1.00']));
    payrollRejectedByDatabase(fn () => DB::table('payroll_payslips')->where('id', $this->payslip->id)->delete());
    payrollRejectedByDatabase(fn () => DB::table('payroll_payslip_lines')->where('payslip_id', $this->payslip->id)->update(['amount' => '1.00']));
    payrollRejectedByDatabase(fn () => DB::table('payroll_payslip_lines')->where('payslip_id', $this->payslip->id)->delete());

    expect(DB::table('payroll_payslips')->where('id', $this->payslip->id)->value('gross_salary'))->toBe('6500.00');
});

test('the inputs of a closed month are frozen', function (): void {
    $payload = ['employee_id' => $this->employee->id, 'year' => 2026, 'month' => 1, 'wage_type_code' => '1100', 'amount' => '100'];

    payrollApi($this->manager, $this->tenant)->postJson('/api/v1/payroll/inputs', $payload)->assertConflict();

    $input = payrollApi($this->manager, $this->tenant)->postJson('/api/v1/payroll/inputs', [...$payload, 'month' => 2])->assertCreated()->json('data.id');

    payrollApi($this->manager, $this->tenant)->putJson("/api/v1/payroll/inputs/{$input}", $payload)->assertConflict();
    expect(PayrollInput::query()->withoutGlobalScopes()->findOrFail($input)->month)->toBe(2);
});

test('reopening needs a reason and leaves a trace', function (): void {
    payrollApi($this->manager, $this->tenant)->postJson("/api/v1/payroll/runs/{$this->run->id}/reopen", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');

    payrollApi($this->manager, $this->tenant)->postJson("/api/v1/payroll/runs/{$this->run->id}/reopen", ['reason' => 'Bonus dimenticato'])
        ->assertOk()
        ->assertJsonPath('data.status', 'Draft')
        ->assertJsonPath('data.finalized_at', null)
        ->assertJsonPath('data.reopened_by', $this->manager->id)
        ->assertJsonPath('data.reopen_reason', 'Bonus dimenticato')
        ->assertJsonPath('data.reopen_count', 1);

    $event = PayrollRunEvent::query()->withoutGlobalScopes()->where('payroll_run_id', $this->run->id)->where('action', 'Reopened')->sole();

    expect($event->reason)->toBe('Bonus dimenticato')
        ->and($event->actor_id)->toBe($this->manager->id)
        ->and($event->actor_name)->toBe($this->manager->name)
        ->and($event->totals['gross_salary'])->toBe('6500.00')
        ->and(TransactionalOutbox::query()->where('aggregate_id', $this->run->id)->where('event_type', 'accounting.payroll.run_reopened')->count())->toBe(1);

    payrollCalculate($this->tenant, $this->manager, 2026, 1)->assertCreated();
});

test('after a reopening the database protection is back at once', function (): void {
    payrollApi($this->manager, $this->tenant)->postJson("/api/v1/payroll/runs/{$this->run->id}/reopen", ['reason' => 'Correzione'])->assertOk();
    payrollApi($this->manager, $this->tenant)->postJson("/api/v1/payroll/runs/{$this->run->id}/approve")->assertOk();
    payrollApi($this->manager, $this->tenant)->postJson("/api/v1/payroll/runs/{$this->run->id}/finalize")->assertOk();

    payrollRejectedByDatabase(fn () => DB::table('payroll_runs')->where('id', $this->run->id)->update(['status' => 'Draft']));
});

test('only the latest run can be reopened', function (): void {
    payrollCalculate($this->tenant, $this->manager, 2026, 2)->assertCreated();

    payrollApi($this->manager, $this->tenant)->postJson("/api/v1/payroll/runs/{$this->run->id}/reopen", ['reason' => 'Correzione'])
        ->assertUnprocessable()
        ->assertJsonPath('detail', "Esistono elaborazioni successive: si può riaprire solo l'ultima elaborazione.");

    expect(PayrollRun::query()->withoutGlobalScopes()->findOrFail($this->run->id)->status)->toBe('Final');
});

test('a draft cannot be reopened', function (): void {
    $draft = payrollCalculate($this->tenant, $this->manager, 2026, 2)->json('data.id');

    payrollApi($this->manager, $this->tenant)->postJson("/api/v1/payroll/runs/{$draft}/reopen", ['reason' => 'Correzione'])->assertUnprocessable();
});

test('the history of a run is append-only', function (): void {
    $event = PayrollRunEvent::query()->withoutGlobalScopes()->where('payroll_run_id', $this->run->id)->first();

    expect(fn () => $event->update(['reason' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('payroll_run_events')->where('id', $event->id)->update(['reason' => 'x'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('payroll_run_events')->where('id', $event->id)->delete()))->toThrow(QueryException::class);
});
