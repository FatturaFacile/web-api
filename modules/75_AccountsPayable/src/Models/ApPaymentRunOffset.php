<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * The part of a supplier credit note offset against a supplier invoice by a payment run.
 *
 * @property string $id
 * @property string $payment_run_id
 * @property string $debit_item_id
 * @property string $credit_item_id
 * @property string $amount
 */
final class ApPaymentRunOffset extends BaseEnterpriseModel
{
    protected $table = 'ap_payment_run_offsets';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
