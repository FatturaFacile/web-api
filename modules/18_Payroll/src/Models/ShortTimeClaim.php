<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The monthly reimbursement request of a case.
 */
final class ShortTimeClaim extends PayrollRecord
{
    public const string StatusDraft = 'draft';

    public const string StatusSubmitted = 'submitted';

    public const string StatusReimbursed = 'reimbursed';

    protected $table = 'payroll_short_time_claims';

    /**
     * @return BelongsTo<ShortTimeCase, $this>
     */
    public function shortTimeCase(): BelongsTo
    {
        return $this->belongsTo(ShortTimeCase::class, 'short_time_case_id');
    }

    /**
     * @return HasMany<ShortTimeLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ShortTimeLine::class, 'short_time_claim_id');
    }

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'parameters_year' => 'integer',
            'target_hours' => 'decimal:2',
            'lost_hours' => 'decimal:2',
            'loss_percent' => 'decimal:2',
            'meets_minimum_loss' => 'boolean',
            'total_compensation' => 'decimal:2',
            'total_employer_refund' => 'decimal:2',
            'claim_due_on' => 'date:Y-m-d',
            'warnings' => 'array',
            'submitted_at' => 'datetime',
            'applied_at' => 'datetime',
            'reimbursed_at' => 'datetime',
            'reimbursed_amount' => 'decimal:2',
        ];
    }
}
