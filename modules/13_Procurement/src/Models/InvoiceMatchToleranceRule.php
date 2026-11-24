<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;
use Modules\Procurement\Enums\ToleranceScope;

/**
 * A tolerance of the invoice matching: how far an invoice line may drift from the order price (percentage of the line
 * amount or absolute amount, whichever is larger) and from the quantity received. Scoped to the tenant, a supplier, an
 * item category or a named rule.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property ToleranceScope $scope
 * @property string|null $supplier_id
 * @property string|null $item_category
 * @property string|null $match_type
 * @property int $priority
 * @property string $price_tolerance_percent
 * @property string $price_tolerance_amount
 * @property string $quantity_tolerance_percent
 * @property string $quantity_tolerance_amount
 * @property string|null $currency
 * @property bool $is_active
 * @property string|null $notes
 * @property Carbon|null $created_at
 */
final class InvoiceMatchToleranceRule extends BaseEnterpriseModel
{
    protected $table = 'invoice_match_tolerance_rules';

    protected function casts(): array
    {
        return [
            'scope' => ToleranceScope::class,
            'priority' => 'integer',
            'price_tolerance_percent' => 'decimal:2',
            'price_tolerance_amount' => 'decimal:2',
            'quantity_tolerance_percent' => 'decimal:2',
            'quantity_tolerance_amount' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }
}
