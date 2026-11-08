<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use App\Services\Sales\DocumentNumberingService;

/**
 * Gapless numbers of procurement documents from the shared document series of the company. Purchase orders and goods
 * receipts use the same series as the purchasing API of the app, so the two can never produce a duplicate.
 */
final readonly class ProcurementNumberingService
{
    public const string PurchaseOrder = 'purchase_order';

    public const string GoodsReceipt = 'goods_receipt';

    public const string Requisition = 'purchase_requisition';

    public const string PurchaseReturn = 'purchase_return';

    public const string Rfq = 'rfq';

    public const string LandedCost = 'landed_cost';

    public function __construct(private DocumentNumberingService $numbering) {}

    /**
     * Reserves the next number of the series in the year of the document date (default: current year of the company).
     */
    public function next(string $series, string $tenantId, ?string $documentDate = null): string
    {
        $year = $documentDate !== null && $documentDate !== '' ? (int) substr($documentDate, 0, 4) : null;

        return $this->numbering->next($series, $year, $tenantId)['number'];
    }
}
