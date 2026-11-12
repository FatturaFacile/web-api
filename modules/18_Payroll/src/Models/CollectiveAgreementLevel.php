<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

use Enterprise\Foundation\Concerns\Auditable;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A level of a collective agreement with its monthly minimum.
 */
final class CollectiveAgreementLevel extends PayrollRecord
{
    use AllowsGlobalRecords, Auditable;

    protected $table = 'payroll_collective_agreement_levels';

    protected function casts(): array
    {
        return ['monthly_minimum' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<CollectiveAgreement, $this>
     */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(CollectiveAgreement::class, 'agreement_id');
    }
}
