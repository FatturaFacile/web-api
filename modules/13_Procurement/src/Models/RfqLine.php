<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * An item asked for in a request for quotation.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $rfq_id
 * @property int $line_number
 * @property string|null $purchase_requisition_line_id
 * @property string|null $internal_sku
 * @property string $description
 * @property string $quantity
 * @property string $uom
 * @property Carbon|null $required_by_date
 */
final class RfqLine extends BaseEnterpriseModel
{
    protected $table = 'procurement_rfq_lines';

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'quantity' => 'decimal:4',
            'required_by_date' => 'date',
        ];
    }
}
