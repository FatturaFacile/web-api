<?php

declare(strict_types=1);

namespace App\Application\Procurement\Rfq\DTOs;

use App\Domain\Procurement\Rfq\Models\RequestForQuotation;
use Modules\Procurement\Models\PurchaseOrder;

/**
 * Result returned by AwardRfqHandler containing the updated RFQ and generated Purchase Orders.
 */
final readonly class AwardRfqResult
{
    /**
     * @param  list<PurchaseOrder>  $purchaseOrders
     */
    public function __construct(
        public RequestForQuotation $rfq,
        public array $purchaseOrders,
    ) {}
}
