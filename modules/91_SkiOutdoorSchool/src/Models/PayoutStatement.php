<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\SkiOutdoorSchool\Domain\Enums\CollaborationRegime;
use Modules\SkiOutdoorSchool\Domain\Enums\PayoutStatus;

/**
 * Monthly payout statement (estratto conto compensi) of an instructor.
 *
 * @property string $instructor_id
 * @property string $period_month
 * @property string $reference
 * @property string $hours_total
 * @property string $private_hours
 * @property string $group_hours
 * @property string $gross_total
 * @property string $retention_total
 * @property string $withholding_percent
 * @property string $withholding_amount
 * @property string $net_payable
 * @property CollaborationRegime $regime
 * @property PayoutStatus $status
 * @property Carbon|null $issued_at
 */
final class PayoutStatement extends BaseEnterpriseModel
{
    protected $table = 'instructor_payout_statements';

    protected function casts(): array
    {
        return [
            'hours_total' => 'decimal:2',
            'private_hours' => 'decimal:2',
            'group_hours' => 'decimal:2',
            'gross_total' => 'decimal:2',
            'retention_total' => 'decimal:2',
            'withholding_percent' => 'decimal:2',
            'withholding_amount' => 'decimal:2',
            'net_payable' => 'decimal:2',
            'regime' => CollaborationRegime::class,
            'status' => PayoutStatus::class,
            'issued_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Instructor, $this>
     */
    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class, 'instructor_id');
    }

    /**
     * @return HasMany<InstructorCommission, $this>
     */
    public function commissions(): HasMany
    {
        return $this->hasMany(InstructorCommission::class, 'payout_statement_id');
    }
}
