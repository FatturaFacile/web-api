<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

use Enterprise\Foundation\Concerns\Auditable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A collective agreement (CCNL): the tables are loaded by the user.
 */
final class CollectiveAgreement extends PayrollRecord
{
    use Auditable;

    protected $table = 'payroll_collective_agreements';

    protected function casts(): array
    {
        return [
            'weekly_hours' => 'decimal:2',
            'installments' => 'integer',
            'fourteenth_month' => 'integer',
            'employer_inps_rate_percent' => 'decimal:4',
        ];
    }

    /**
     * @return HasMany<CollectiveAgreementLevel, $this>
     */
    public function levels(): HasMany
    {
        return $this->hasMany(CollectiveAgreementLevel::class, 'agreement_id')->orderBy('level');
    }
}
