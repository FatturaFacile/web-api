<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * An invoice under collection in a case, with the amount that was outstanding when last refreshed.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $case_id
 * @property string $invoice_id
 * @property string $invoice_number
 * @property Carbon|null $due_on
 * @property string $outstanding_amount
 */
final class CreditCaseInvoice extends BaseEnterpriseModel
{
    protected $table = 'credit_case_invoices';

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'outstanding_amount' => 'decimal:2',
        ];
    }
}
