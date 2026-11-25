<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string $route_code
 * @property string $driver_agent_name
 * @property string $vehicle_plate
 * @property string $traveling_warehouse_code
 * @property string $document_series_sezionale
 * @property string $loaded_stock_qty
 * @property string $sold_stock_qty
 * @property string $returned_unsold_qty
 * @property string $stock_variance_qty
 * @property string $expected_cash_amount
 * @property string $collected_cash_amount
 * @property string $electronic_payment_amount
 * @property string $cash_discrepancy_amount
 * @property string $currency
 * @property string|null $bluetooth_escpos_payload
 * @property string|null $offline_sync_batch_id
 * @property string $reconciliation_status
 */
final class SbcVanSellingRoute extends BaseEnterpriseModel
{
    protected $table = 'sbc_van_selling_routes';

    protected function casts(): array
    {
        return [
            'loaded_stock_qty' => 'decimal:3',
            'sold_stock_qty' => 'decimal:3',
            'returned_unsold_qty' => 'decimal:3',
            'stock_variance_qty' => 'decimal:3',
            'expected_cash_amount' => 'decimal:2',
            'collected_cash_amount' => 'decimal:2',
            'electronic_payment_amount' => 'decimal:2',
            'cash_discrepancy_amount' => 'decimal:2',
        ];
    }
}
