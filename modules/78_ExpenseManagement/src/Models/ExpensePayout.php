<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * The record of a payout of an approved report: a pain.001 credit transfer file or the payroll items.
 *
 * @property string $expense_report_id
 * @property string $channel
 * @property string $status
 * @property string|null $message_id
 * @property string $total_amount
 * @property string $taxable_amount
 * @property string $exempt_amount
 * @property string $currency
 * @property Carbon|null $execution_date
 * @property string|null $file_name
 * @property string|null $disk
 * @property string|null $path
 * @property array<string, mixed>|null $payload
 */
final class ExpensePayout extends BaseEnterpriseModel
{
    protected $table = 'exm_payouts';

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'exempt_amount' => 'decimal:2',
            'execution_date' => 'date',
            'payload' => 'array',
        ];
    }
}
