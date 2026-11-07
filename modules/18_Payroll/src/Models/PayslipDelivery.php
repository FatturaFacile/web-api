<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payslip offered through a personal link. Only the hash of the link is stored.
 */
final class PayslipDelivery extends PayrollRecord
{
    protected $table = 'payroll_payslip_deliveries';

    protected $hidden = ['token_hash'];

    /**
     * @return BelongsTo<Payslip, $this>
     */
    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class, 'payslip_id');
    }

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'opened_at' => 'datetime', 'revoked_at' => 'datetime', 'failed_attempts' => 'integer'];
    }
}
