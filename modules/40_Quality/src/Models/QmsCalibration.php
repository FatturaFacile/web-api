<?php

declare(strict_types=1);

namespace Modules\Quality\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One calibration of an instrument with its certificate. Out of tolerance means the as-found error exceeded the
 * tolerance of the instrument.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $instrument_id
 * @property Carbon $calibrated_on
 * @property Carbon $next_due_on
 * @property string $result
 * @property string|null $as_found_error
 * @property string|null $as_left_error
 * @property string|null $laboratory
 * @property string|null $certificate_number
 * @property string|null $certificate_document_id
 * @property string|null $performed_by
 * @property string|null $nonconformity_id
 * @property string|null $notes
 */
final class QmsCalibration extends BaseEnterpriseModel
{
    public const array Results = ['pass', 'adjusted', 'out_of_tolerance'];

    protected $table = 'qms_calibrations';

    protected function casts(): array
    {
        return [
            'calibrated_on' => 'date',
            'next_due_on' => 'date',
            'as_found_error' => 'decimal:5',
            'as_left_error' => 'decimal:5',
        ];
    }

    /**
     * @return BelongsTo<QmsInstrument, $this>
     */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(QmsInstrument::class, 'instrument_id');
    }
}
