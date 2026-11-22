<?php

declare(strict_types=1);

namespace Modules\Rebates\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The attribution of a supplier invoice to an accrual run. The net amount is signed: credit notes (TD04) are negative.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $accrual_run_id
 * @property string|null $passive_invoice_id
 * @property string|null $invoice_number
 * @property string|null $document_type
 * @property Carbon|null $invoice_date
 * @property string $net_amount
 */
final class RebateAccrualLine extends BaseEnterpriseModel
{
    protected $table = 'rebate_accrual_lines';

    protected function casts(): array
    {
        return ['invoice_date' => 'date', 'net_amount' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<RebateAccrualRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(RebateAccrualRun::class, 'accrual_run_id');
    }
}
