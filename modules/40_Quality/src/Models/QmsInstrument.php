<?php

declare(strict_types=1);

namespace Modules\Quality\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A measuring instrument under calibration control. The status is active, quarantined (the last calibration was out of
 * tolerance: results measured with it are suspect) or retired. The next due date is derived from the last calibration.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $serial_number
 * @property string|null $manufacturer
 * @property string|null $location
 * @property string|null $unit
 * @property string|null $range_min
 * @property string|null $range_max
 * @property string|null $tolerance
 * @property int $calibration_interval_days
 * @property string $status
 * @property Carbon|null $last_calibrated_on
 * @property Carbon|null $next_due_on
 * @property string|null $notes
 */
final class QmsInstrument extends BaseEnterpriseModel
{
    public const array Statuses = ['active', 'quarantined', 'retired'];

    /** Days before the due date at which an instrument is reported as due soon. */
    public const int DueSoonDays = 30;

    protected $table = 'qms_instruments';

    protected function casts(): array
    {
        return [
            'range_min' => 'decimal:5',
            'range_max' => 'decimal:5',
            'tolerance' => 'decimal:5',
            'calibration_interval_days' => 'integer',
            'last_calibrated_on' => 'date',
            'next_due_on' => 'date',
        ];
    }

    /**
     * @return HasMany<QmsCalibration, $this>
     */
    public function calibrations(): HasMany
    {
        return $this->hasMany(QmsCalibration::class, 'instrument_id')->orderByDesc('calibrated_on');
    }

    /**
     * valid, due_soon, overdue (never calibrated counts as overdue), quarantined or retired.
     */
    public function calibrationStatus(): string
    {
        if ($this->status !== 'active') {
            return $this->status;
        }
        if ($this->next_due_on === null || $this->next_due_on->endOfDay()->isPast()) {
            return 'overdue';
        }

        return $this->next_due_on->lte(now()->addDays(self::DueSoonDays)->endOfDay()) ? 'due_soon' : 'valid';
    }
}
