<?php

declare(strict_types=1);

namespace Modules\Procurement\Contracts;

use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseReceipt;
use Modules\Procurement\Models\ThreeWayMatchLog;

interface AccountingProcurementBridgeInterface
{
    /**
     * Bridges encumbrance commitments into General Ledger accounts.
     */
    public function recordPurchaseCommitment(PurchaseOrder $order): void;

    /**
     * Bridges goods receipt accruals (GR/IR clearing account).
     */
    public function recordGoodsReceiptAccrual(PurchaseReceipt $receipt): void;

    /**
     * Bridges matched supplier invoice release to accounts payable.
     */
    public function recordInvoiceMatchPosting(ThreeWayMatchLog $matchLog): void;
}
