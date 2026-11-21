<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Application\Handlers;

use Illuminate\Support\Facades\DB;
use Modules\ItalianPayroll\Application\Commands\ValidateMonthlyLulAttendanceCommand;
use Modules\ItalianPayroll\Domain\Enums\PeriodStatus;
use Modules\ItalianPayroll\Domain\Exceptions\ItalianPayrollException;
use Modules\ItalianPayroll\Infrastructure\Models\ItalianPayrollPeriod;
use Modules\ItalianPayroll\Infrastructure\Models\LulAttendanceExportRecord;
use Modules\ItalianPayroll\Infrastructure\Services\AttendanceAggregator;

/**
 * Rebuilds the LUL lines of an open month from the operational modules and runs the consistency checks. Errors keep the
 * month open (the report says what to fix); with no error the month moves to Validated and its lines are frozen.
 */
final class ValidateMonthlyLulAttendanceHandler
{
    public function __construct(private readonly AttendanceAggregator $aggregator) {}

    /**
     * @return array{period_id: string, status: string, validated: bool, line_count: int, errors: list<array<string, string>>, warnings: list<array<string, string>>}
     */
    public function handle(ValidateMonthlyLulAttendanceCommand $command): array
    {
        return DB::transaction(function () use ($command): array {
            $period = ItalianPayrollPeriod::query()->lockForUpdate()->findOrFail($command->periodId);

            if ($period->status !== PeriodStatus::AttendanceOpen) {
                throw new ItalianPayrollException("Il periodo {$period->month}/{$period->year} è già {$period->status->value}.");
            }

            $result = $this->aggregator->aggregate($period, $command->fringeBenefits);

            LulAttendanceExportRecord::query()->where('payroll_period_id', $period->id)->get()->each->delete();

            foreach ($result['lines'] as $line) {
                LulAttendanceExportRecord::query()->create($line + ['payroll_period_id' => $period->id]);
            }

            $period->validation_report_json = ['errors' => $result['errors'], 'warnings' => $result['warnings'], 'checked_at' => now()->toIso8601String()];
            $validated = $result['errors'] === [];

            if ($validated) {
                $period->advanceTo(PeriodStatus::Validated, $command->actorId);
            } else {
                $period->save();
            }

            return [
                'period_id' => (string) $period->id,
                'status' => $period->status->value,
                'validated' => $validated,
                'line_count' => count($result['lines']),
                'errors' => $result['errors'],
                'warnings' => $result['warnings'],
            ];
        });
    }
}
