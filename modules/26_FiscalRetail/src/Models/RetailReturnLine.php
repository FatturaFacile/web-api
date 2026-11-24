<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Models;

use Database\Factories\RetailReturnLineFactory;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A returned quantity of a POS sale line with the refunded net, tax and gross.
 */
#[UseFactory(RetailReturnLineFactory::class)]
final class RetailReturnLine extends Model
{
    /** @use HasFactory<RetailReturnLineFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $table = 'retail_return_lines';

    protected $fillable = ['tenant_id', 'retail_return_id', 'pos_sale_item_id', 'product_id', 'description', 'quantity', 'tax_rate', 'net_amount', 'tax_amount', 'gross_amount'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'tax_rate' => 'decimal:2', 'net_amount' => 'decimal:2', 'tax_amount' => 'decimal:2', 'gross_amount' => 'decimal:2'];
    }
}
