<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Procurement tolerances of a company: over and under receipt against the order, price variance between supplier
 * invoice and received goods, and the clearing account of landed costs. Zero means no tolerance.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $over_receipt_tolerance_percent
 * @property string $under_receipt_tolerance_percent
 * @property string $price_tolerance_percent
 * @property string $price_tolerance_amount
 * @property bool $block_invoice_on_mismatch
 * @property string $landed_cost_clearing_account_code
 */
final class ProcurementSetting extends BaseEnterpriseModel
{
    protected $table = 'procurement_settings';

    protected function casts(): array
    {
        return [
            'over_receipt_tolerance_percent' => 'decimal:2',
            'under_receipt_tolerance_percent' => 'decimal:2',
            'price_tolerance_percent' => 'decimal:2',
            'price_tolerance_amount' => 'decimal:2',
            'block_invoice_on_mismatch' => 'boolean',
        ];
    }
}
