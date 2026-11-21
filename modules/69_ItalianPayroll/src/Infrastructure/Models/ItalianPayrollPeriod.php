<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\ItalianPayroll\Domain\Enums\PeriodStatus;
use Modules\ItalianPayroll\Domain\Exceptions\ItalianPayrollException;

/**
 * One payroll month of a legal entity and where it stands in the closing workflow.
 *
 * @property string $id
 * @property string|null $legal_entity_id
 * @property int $year
 * @property int $month
 * @property bool $include_tredicesima
 * @property bool $include_quattordicesima
 * @property PeriodStatus $status
 * @property string|null $export_format
 * @property string|null $export_checksum
 * @property array<string, mixed>|null $validation_report_json
 * @property Carbon|null $validated_at
 * @property Carbon|null $exported_at
 * @property Carbon|null $paid_at
 */
final class ItalianPayrollPeriod extends BaseEnterpriseModel
{
    protected $table = 'ipp_payroll_periods';

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'include_tredicesima' => 'boolean',
            'include_quattordicesima' => 'boolean',
            'status' => PeriodStatus::class,
            'validation_report_json' => 'array',
            'validated_at' => 'datetime',
            'exported_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * Advances to the next state of the workflow; any other jump is refused.
     */
    public function advanceTo(PeriodStatus $target, ?string $actorId = null): void
    {
        if (! $this->status->canAdvanceTo($target)) {
            throw new ItalianPayrollException("Il periodo {$this->month}/{$this->year} non può passare da {$this->status->value} a {$target->value}.");
        }

        $this->status = $target;
        match ($target) {
            PeriodStatus::Validated => [$this->validated_at, $this->validated_by] = [now(), $actorId],
            PeriodStatus::ExportedToCdl => [$this->exported_at, $this->exported_by] = [now(), $actorId],
            PeriodStatus::Paid => $this->paid_at = now(),
            default => null,
        };
        $this->save();
    }

    /**
     * @return HasMany<LulAttendanceExportRecord, $this>
     */
    public function lulRecords(): HasMany
    {
        return $this->hasMany(LulAttendanceExportRecord::class, 'payroll_period_id');
    }
}
