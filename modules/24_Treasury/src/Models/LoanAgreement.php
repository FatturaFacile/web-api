<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LoanAgreement extends BaseEnterpriseModel
{
    protected $table = 'loan_agreements';

    protected function casts(): array
    {
        return [
            'principal_amount' => 'decimal:2',
            'nominal_rate' => 'decimal:4',
            'spread_rate' => 'decimal:4',
            'origination_date' => 'date',
            'maturity_date' => 'date',
            'covenant_clause_json' => 'array',
        ];
    }

    public function lenderAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'lender_bank_account_id');
    }

    public function drawdowns(): HasMany
    {
        return $this->hasMany(LoanDrawdown::class, 'loan_agreement_id');
    }

    public function interestSchedules(): HasMany
    {
        return $this->hasMany(InterestSchedule::class, 'loan_agreement_id');
    }
}
