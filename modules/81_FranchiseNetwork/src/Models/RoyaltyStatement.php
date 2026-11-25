<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\FranchiseNetwork\Domain\Enums\StatementStatus;

/**
 * Statement of one store inside a billing run: royalty, minimum guarantee adjustment, marketing fund and loyalty settlement.
 *
 * @property string $run_id
 * @property string $store_id
 * @property string|null $agreement_id
 * @property string|null $formula_id
 * @property string $period
 * @property StatementStatus $status
 * @property string|null $skip_reason
 * @property string $net_sales
 * @property string $royalty_gross
 * @property string $startup_factor
 * @property string $royalty_after_startup
 * @property string $minimum_guarantee_applied
 * @property string $minimum_adjustment
 * @property string $royalty_charged
 * @property string $marketing_fund
 * @property string $loyalty_settlement
 * @property string $total_net
 * @property array<string, mixed>|null $breakdown
 * @property string|null $invoice_id
 */
final class RoyaltyStatement extends BaseEnterpriseModel
{
    protected $table = 'frn_royalty_statements';

    protected function casts(): array
    {
        return [
            'status' => StatementStatus::class,
            'net_sales' => 'decimal:2',
            'royalty_gross' => 'decimal:2',
            'startup_factor' => 'decimal:6',
            'royalty_after_startup' => 'decimal:2',
            'minimum_guarantee_applied' => 'decimal:2',
            'minimum_adjustment' => 'decimal:2',
            'royalty_charged' => 'decimal:2',
            'marketing_fund' => 'decimal:2',
            'loyalty_settlement' => 'decimal:2',
            'total_net' => 'decimal:2',
            'breakdown' => 'array',
        ];
    }

    /**
     * @return BelongsTo<FranchiseStore, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(FranchiseStore::class, 'store_id');
    }

    /**
     * @return BelongsTo<RoyaltyRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(RoyaltyRun::class, 'run_id');
    }
}
