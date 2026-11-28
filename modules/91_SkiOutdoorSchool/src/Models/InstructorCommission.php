<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\SkiOutdoorSchool\Domain\Enums\CommissionSource;
use Modules\SkiOutdoorSchool\Domain\Enums\RetentionMode;

/**
 * Fee of an instructor for one completed lesson or course, with the secretariat retention applied.
 *
 * @property string $instructor_id
 * @property CommissionSource $source_type
 * @property string $source_id
 * @property Carbon $work_date
 * @property string $hours
 * @property string $gross_fee
 * @property RetentionMode $retention_mode
 * @property string $retention_value
 * @property string $retention_amount
 * @property string $net_fee
 * @property string|null $payout_statement_id
 */
final class InstructorCommission extends BaseEnterpriseModel
{
    protected $table = 'instructor_commissions';

    protected function casts(): array
    {
        return [
            'source_type' => CommissionSource::class,
            'work_date' => 'date:Y-m-d',
            'hours' => 'decimal:2',
            'gross_fee' => 'decimal:2',
            'retention_mode' => RetentionMode::class,
            'retention_value' => 'decimal:4',
            'retention_amount' => 'decimal:2',
            'net_fee' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Instructor, $this>
     */
    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class, 'instructor_id');
    }
}
