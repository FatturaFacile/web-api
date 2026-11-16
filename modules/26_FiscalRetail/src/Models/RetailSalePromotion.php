<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Models;

use Database\Factories\RetailSalePromotionFactory;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A promotion applied to a POS sale with the net discount it produced.
 */
#[UseFactory(RetailSalePromotionFactory::class)]
final class RetailSalePromotion extends Model
{
    /** @use HasFactory<RetailSalePromotionFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $table = 'retail_sale_promotions';

    protected $fillable = ['tenant_id', 'pos_sale_id', 'promotion_id', 'code', 'name', 'discount_net'];

    protected function casts(): array
    {
        return ['discount_net' => 'decimal:2'];
    }
}
