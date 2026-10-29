<?php

declare(strict_types=1);

namespace Modules\Procurement\Contracts;

use Modules\Procurement\Models\PurchaseReceipt;
use Modules\Procurement\Models\PurchaseReturn;

interface InventoryReceivingBridgeInterface
{
    /**
     * Bridges goods receipt to warehouse inventory stock quants.
     */
    public function recordStockReceipt(PurchaseReceipt $receipt): void;

    /**
     * Bridges purchase return to warehouse inventory deduction.
     */
    public function recordStockReturn(PurchaseReturn $return): void;
}
