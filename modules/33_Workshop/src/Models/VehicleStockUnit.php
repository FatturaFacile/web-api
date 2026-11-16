<?php

declare(strict_types=1);

namespace Modules\Workshop\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A new or used vehicle held for sale.
 *
 * @property string $id
 * @property ?string $tenant_id
 * @property string $stock_number
 * @property string $status
 * @property string $condition
 * @property ?string $license_plate
 * @property ?string $vin
 * @property string $make
 * @property string $model
 * @property ?int $year
 * @property int $mileage_km
 * @property string $currency
 */
final class VehicleStockUnit extends WorkshopRecord
{
    use SoftDeletes;

    protected $table = 'workshop_vehicle_stock';

    /** @var list<string> */
    protected $appends = ['sold_at', 'buyer_name'];

    /**
     * Calculated grid columns (portable SQL, see the GridColumns class of the grid engine). They mirror the appended
     * attributes below, so filter, sort, group and the value lists run over the whole dataset.
     *
     * @return array<string, string>
     */
    public function gridColumns(): array
    {
        return [
            'sold_at' => '(SELECT SUBSTR(CAST(workshop_vehicle_sales.sale_date AS TEXT), 1, 10) FROM workshop_vehicle_sales WHERE workshop_vehicle_sales.stock_id = workshop_vehicle_stock.id)',
            'buyer_name' => "(SELECT COALESCE(NULLIF(contacts.company_name, ''), NULLIF(TRIM(COALESCE(contacts.first_name, '') || ' ' || COALESCE(contacts.last_name, '')), ''), '-') FROM workshop_vehicle_sales INNER JOIN contacts ON contacts.id = workshop_vehicle_sales.buyer_contact_id WHERE workshop_vehicle_sales.stock_id = workshop_vehicle_stock.id)",
        ];
    }

    protected function casts(): array
    {
        return ['purchase_price' => 'decimal:2', 'list_price' => 'decimal:2', 'year' => 'integer', 'mileage_km' => 'integer'];
    }

    /**
     * @return HasOne<VehicleSale, $this>
     */
    public function sale(): HasOne
    {
        return $this->hasOne(VehicleSale::class, 'stock_id');
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function soldAt(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->sale?->sale_date?->toDateString());
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function buyerName(): Attribute
    {
        return Attribute::get(function (): ?string {
            $buyer = $this->sale?->buyer;

            if ($buyer === null) {
                return null;
            }

            $person = trim(($buyer->first_name ?? '').' '.($buyer->last_name ?? ''));

            return filled($buyer->company_name) ? $buyer->company_name : ($person !== '' ? $person : '-');
        });
    }
}
