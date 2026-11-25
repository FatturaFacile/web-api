<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\VanSalesAndFieldForce\Domain\Enums\SettlementStatus;

/**
 * End-of-day van reconciliation ("Quadratura Fine Giro"):
 * stock reconciliation (keep onboard vs unload to central depot), empties, cold-chain log summary,
 * and cash/cheque/POS/TWINT treasury deposit slip ("Verbale di Versamento").
 *
 * @property string $van_warehouse_id
 * @property string|null $van_load_manifest_id
 * @property string|null $field_route_plan_id
 * @property string $settlement_number
 * @property Carbon $settlement_date
 * @property SettlementStatus $status
 * @property array<int, array<string, mixed>> $stock_reconciliation_lines
 * @property string $total_loaded_qty
 * @property string $total_sold_qty
 * @property string $total_returned_damaged_qty
 * @property string $total_freebies_qty
 * @property string $total_remaining_qty
 * @property string $total_keep_onboard_qty
 * @property string $total_unload_central_qty
 * @property array<int, array<string, mixed>>|null $empties_reconciliation
 * @property array<string, mixed>|null $cold_chain_summary
 * @property string $opening_cash_float
 * @property string $collected_cash_amount
 * @property string $collected_pos_card_amount
 * @property string $collected_twint_amount
 * @property string $collected_cheque_amount
 * @property string $collected_bank_transfer_amount
 * @property string $expected_cash_remittance
 * @property string $counted_cash_remittance
 * @property string $cash_discrepancy_amount
 * @property string $total_remittance_amount
 * @property string|null $deposit_slip_number
 * @property bool $treasury_deposited
 * @property bool $central_warehouse_unloaded
 * @property string $currency
 * @property string|null $reconciled_by
 * @property Carbon|null $reconciled_at
 * @property string|null $notes
 */
final class VanEodSettlement extends BaseEnterpriseModel
{
    protected $table = 'van_eod_settlements';

    protected function casts(): array
    {
        return [
            'settlement_date' => 'date',
            'status' => SettlementStatus::class,
            'stock_reconciliation_lines' => 'array',
            'total_loaded_qty' => 'decimal:4',
            'total_sold_qty' => 'decimal:4',
            'total_returned_damaged_qty' => 'decimal:4',
            'total_freebies_qty' => 'decimal:4',
            'total_remaining_qty' => 'decimal:4',
            'total_keep_onboard_qty' => 'decimal:4',
            'total_unload_central_qty' => 'decimal:4',
            'empties_reconciliation' => 'array',
            'cold_chain_summary' => 'array',
            'opening_cash_float' => 'decimal:2',
            'collected_cash_amount' => 'decimal:2',
            'collected_pos_card_amount' => 'decimal:2',
            'collected_twint_amount' => 'decimal:2',
            'collected_cheque_amount' => 'decimal:2',
            'collected_bank_transfer_amount' => 'decimal:2',
            'expected_cash_remittance' => 'decimal:2',
            'counted_cash_remittance' => 'decimal:2',
            'cash_discrepancy_amount' => 'decimal:2',
            'total_remittance_amount' => 'decimal:2',
            'treasury_deposited' => 'boolean',
            'central_warehouse_unloaded' => 'boolean',
            'reconciled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<VanWarehouse, $this>
     */
    public function vanWarehouse(): BelongsTo
    {
        return $this->belongsTo(VanWarehouse::class, 'van_warehouse_id');
    }
}
