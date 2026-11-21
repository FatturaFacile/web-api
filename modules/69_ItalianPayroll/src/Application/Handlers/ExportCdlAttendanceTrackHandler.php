<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Application\Handlers;

use Illuminate\Support\Facades\DB;
use Modules\ItalianPayroll\Application\Commands\ExportCdlAttendanceTrackCommand;
use Modules\ItalianPayroll\Domain\Enums\PeriodStatus;
use Modules\ItalianPayroll\Domain\Exceptions\ItalianPayrollException;
use Modules\ItalianPayroll\Infrastructure\Export\ExportAdapterRegistry;
use Modules\ItalianPayroll\Infrastructure\Export\ExportFile;
use Modules\ItalianPayroll\Infrastructure\Export\ExportRow;
use Modules\ItalianPayroll\Infrastructure\Models\CausalMapping;
use Modules\ItalianPayroll\Infrastructure\Models\ItalianPayrollPeriod;
use Modules\ItalianPayroll\Infrastructure\Models\LulAttendanceExportRecord;

/**
 * Serializes the validated attendance in the layout of the consultant software. The first export moves the month to
 * ExportedToCdl; a later export of the same month produces the file again (the lines are frozen, so it is identical).
 */
final class ExportCdlAttendanceTrackHandler
{
    public function __construct(private readonly ExportAdapterRegistry $adapters) {}

    public function handle(ExportCdlAttendanceTrackCommand $command): ExportFile
    {
        return DB::transaction(function () use ($command): ExportFile {
            $period = ItalianPayrollPeriod::query()->lockForUpdate()->findOrFail($command->periodId);

            if ($period->status->rank() < PeriodStatus::Validated->rank()) {
                throw new ItalianPayrollException('Le presenze devono essere validate prima dell\'esportazione al consulente.');
            }

            $overrides = CausalMapping::query()->where('consultant_format', $command->format->value)->pluck('external_code', 'causal_code');
            $rows = LulAttendanceExportRecord::query()->where('payroll_period_id', $period->id)->orderBy('matricola_inps')->orderBy('causal_code')->get()
                ->map(fn (LulAttendanceExportRecord $record): ExportRow => new ExportRow(
                    (string) $record->matricola_inps,
                    $record->causal_code->value,
                    (string) ($overrides[$record->causal_code->value] ?? $command->format->defaultVoiceCode($record->causal_code)),
                    (string) $record->hours_or_days,
                    $record->causal_code->isHours() ? 'H' : 'G',
                    $record->meal_vouchers_count,
                    (string) $record->reimbursement_amount,
                    (string) $record->fringe_benefit_amount,
                    $record->inps_protocol_number,
                ))->all();

            $file = $this->adapters->for($command->format)->render($period->year, $period->month, $command->companyCode, $rows);

            $period->export_format = $command->format->value;
            $period->export_checksum = $file->checksum();

            if ($period->status === PeriodStatus::Validated) {
                $period->advanceTo(PeriodStatus::ExportedToCdl, $command->actorId);
            } else {
                $period->save();
            }

            return $file;
        });
    }
}
