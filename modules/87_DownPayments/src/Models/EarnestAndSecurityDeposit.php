<?php

declare(strict_types=1);

namespace Modules\DownPayments\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An earnest money deposit (caparra confirmatoria) or security deposit (deposito cauzionale) received from the customer: a
 * liability outside the VAT scope until it is imputed to the price, forfeited or refunded.
 *
 * @property string $chain_id
 * @property string|null $installment_id
 * @property string $deposit_type
 * @property string $receipt_number
 * @property Carbon $issue_date
 * @property Carbon|null $payment_date
 * @property string $amount
 * @property string $currency
 * @property string|null $vat_exemption_code
 * @property bool $marca_da_bollo_applied
 * @property string $marca_da_bollo_amount
 * @property string $payment_account
 * @property string $status
 * @property string|null $converted_invoice_id
 * @property string|null $journal_entry_id
 * @property string|null $resolution_journal_entry_id
 * @property string|null $resolution
 * @property Carbon|null $resolved_at
 * @property string $penalty_amount
 * @property string|null $notes
 */
final class EarnestAndSecurityDeposit extends BaseEnterpriseModel
{
    protected $table = 'earnest_and_security_deposits';

    protected function casts(): array
    {
        return [
            'issue_date' => 'date:Y-m-d',
            'payment_date' => 'date:Y-m-d',
            'resolved_at' => 'date:Y-m-d',
            'amount' => 'decimal:2',
            'marca_da_bollo_applied' => 'boolean',
            'marca_da_bollo_amount' => 'decimal:2',
            'penalty_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<DownPaymentChain, $this>
     */
    public function chain(): BelongsTo
    {
        return $this->belongsTo(DownPaymentChain::class, 'chain_id');
    }
}
