<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One invoice line of a commission statement: the part of a ledger entry that the statement settles.
 *
 * @property string $statement_id
 * @property string $entry_id
 * @property string $invoice_id
 * @property string|null $invoice_number
 * @property Carbon|null $invoice_date
 * @property string|null $customer_name
 * @property string $role
 * @property string $accrual_basis
 * @property string $base_amount
 * @property string|null $rate
 * @property string $commission_amount
 */
final class StatementLine extends BaseEnterpriseModel
{
    protected $table = 'agc_statement_lines';

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'base_amount' => 'decimal:2',
            'rate' => 'decimal:4',
            'commission_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Statement, $this>
     */
    public function statement(): BelongsTo
    {
        return $this->belongsTo(Statement::class, 'statement_id');
    }
}
