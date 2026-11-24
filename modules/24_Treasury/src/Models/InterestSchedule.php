<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class InterestSchedule extends BaseEnterpriseModel
{
    protected $table = 'interest_schedules';

    protected function casts(): array
    {
        return [
            'period_start_date' => 'date',
            'period_end_date' => 'date',
            'due_date' => 'date',
            'principal_due' => 'decimal:2',
            'interest_due' => 'decimal:2',
            'total_due' => 'decimal:2',
            'remaining_balance' => 'decimal:2',
        ];
    }

    public function loanAgreement(): BelongsTo
    {
        return $this->belongsTo(LoanAgreement::class, 'loan_agreement_id');
    }

    public function paymentLine(): BelongsTo
    {
        return $this->belongsTo(PaymentLine::class, 'payment_line_id');
    }
}
