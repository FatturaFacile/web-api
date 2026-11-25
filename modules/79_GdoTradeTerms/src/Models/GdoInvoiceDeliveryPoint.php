<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * The store or Ce.Di. an invoice was delivered to, so that a rebate scale or a promotion can be limited to delivery points.
 *
 * @property string $invoice_id
 * @property string $delivery_point_id
 */
final class GdoInvoiceDeliveryPoint extends BaseEnterpriseModel
{
    protected $table = 'gdt_invoice_delivery_points';
}
