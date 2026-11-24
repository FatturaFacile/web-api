<?php

declare(strict_types=1);

namespace Modules\Intrastat\Models;

use App\Models\Product;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Intrastat\Enums\SupplyKind;

/**
 * The Intrastat data of a product, used to draft the lists from the invoices: whether it is a good or a service, its
 * combined nomenclature (CN8) or CPA code, net mass and supplementary units per unit sold, origin.
 *
 * @property string $id
 * @property string $product_id
 * @property SupplyKind $supply_kind
 * @property string|null $cn8_code
 * @property string|null $net_mass_kg
 * @property string|null $supplementary_units_per_unit
 * @property string|null $service_code
 * @property string|null $origin_country_code
 * @property string|null $origin_province_code
 */
final class IntrastatProductProfile extends IntrastatRecord
{
    protected $table = 'intrastat_product_profiles';

    protected function casts(): array
    {
        return [
            'supply_kind' => SupplyKind::class,
            'net_mass_kg' => 'decimal:4',
            'supplementary_units_per_unit' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
