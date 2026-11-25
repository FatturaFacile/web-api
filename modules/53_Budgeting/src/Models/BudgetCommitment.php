<?php

declare(strict_types=1);

namespace Modules\Budgeting\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * The part of the budget a requisition or purchase order has taken before it is booked in the ledger. The amount is
 * net of tax and in the currency of the budget. It is consumed as the goods are received or invoiced and released when
 * the document is cancelled or replaced (a requisition by its purchase order), so the open amount is
 * amount - consumed - released and is never counted twice.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $source_type
 * @property string $source_id
 * @property string|null $source_number
 * @property string|null $parent_source_id
 * @property string|null $account_id
 * @property string|null $cost_center_id
 * @property string|null $project_id
 * @property int $fiscal_year
 * @property int|null $period
 * @property Carbon $commitment_date
 * @property string $currency
 * @property string $amount
 * @property string $consumed_amount
 * @property string $released_amount
 * @property string $status
 * @property string|null $release_reason
 * @property Carbon|null $closed_at
 */
final class BudgetCommitment extends BaseEnterpriseModel
{
    public const string Requisition = 'requisition';

    public const string PurchaseOrder = 'purchase_order';

    public const string Open = 'open';

    public const string Closed = 'closed';

    public const string Released = 'released';

    protected $table = 'budget_commitments';

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'period' => 'integer',
            'commitment_date' => 'date',
            'amount' => 'decimal:2',
            'consumed_amount' => 'decimal:2',
            'released_amount' => 'decimal:2',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * What is still committed: never below zero.
     */
    public function openAmount(): string
    {
        if ($this->status !== self::Open) {
            return '0.00';
        }

        $open = bcsub(bcsub((string) $this->amount, (string) $this->consumed_amount, 2), (string) $this->released_amount, 2);

        return bccomp($open, '0', 2) < 0 ? '0.00' : $open;
    }
}
