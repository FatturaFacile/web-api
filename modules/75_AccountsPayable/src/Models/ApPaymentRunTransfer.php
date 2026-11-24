<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * One bank transfer of a payment run: everything owed to one creditor IBAN in one currency, in a single payment.
 *
 * @property string $id
 * @property string $payment_run_id
 * @property string $supplier_id
 * @property string|null $creditor_iban
 * @property string $currency
 * @property string $gross_amount
 * @property string $skonto_amount
 * @property string $credit_netted_amount
 * @property string $amount
 * @property int $items_count
 * @property string|null $reference
 * @property string $status
 */
final class ApPaymentRunTransfer extends BaseEnterpriseModel
{
    public const string StatusPending = 'pending';

    public const string StatusPaid = 'paid';

    public const string StatusNetted = 'netted';

    protected $table = 'ap_payment_run_transfers';

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
            'skonto_amount' => 'decimal:2',
            'credit_netted_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'items_count' => 'integer',
        ];
    }
}
