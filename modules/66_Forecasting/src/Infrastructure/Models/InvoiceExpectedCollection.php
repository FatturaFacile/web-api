<?php

declare(strict_types=1);

namespace Modules\Forecasting\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * Expected collection date of an open receivable, from the payment behaviour of its customer. The invoice itself stays
 * untouched (an issued invoice is immutable); the cash flow forecast reads this row.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $invoice_id
 * @property string $customer_id
 * @property Carbon $due_date
 * @property Carbon $expected_collection_date
 * @property Carbon $earliest_collection_date
 * @property Carbon $latest_collection_date
 * @property string $open_amount
 * @property Carbon|null $calculated_at
 */
final class InvoiceExpectedCollection extends BaseEnterpriseModel
{
    protected $table = 'invoice_expected_collections';

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'expected_collection_date' => 'date',
            'earliest_collection_date' => 'date',
            'latest_collection_date' => 'date',
            'open_amount' => 'decimal:2',
            'calculated_at' => 'datetime',
        ];
    }
}
