<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Payroll\Models\Concerns\FrozenByClosedRun;

/**
 * A line of a payslip.
 */
final class PayslipLine extends PayrollRecord
{
    use FrozenByClosedRun;

    protected $table = 'payroll_payslip_lines';

    /**
     * @return BelongsTo<Payslip, $this>
     */
    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class, 'payslip_id');
    }

    protected function owningRun(): ?PayrollRun
    {
        return $this->payslip?->run;
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'rate' => 'decimal:4',
            'amount' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }
}
