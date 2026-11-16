<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use App\Models\Contact;
use App\Models\Product;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Serialized equipment passport ("digital twin" of a sold serial number): warranty, owner and genealogy.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string|null $stock_serial_number_id
 * @property string|null $product_id
 * @property string $serial_number
 * @property string $current_owner_customer_id
 * @property string|null $original_sales_invoice_id
 * @property string $customer_type
 * @property string $jurisdiction
 * @property \Carbon\CarbonImmutable|\Illuminate\Support\Carbon $delivery_date
 * @property string $warranty_policy_type
 * @property \Illuminate\Support\Carbon|null $warranty_start_date
 * @property \Illuminate\Support\Carbon $warranty_expiry_date
 * @property string $warranty_source
 * @property int|null $contract_warranty_months
 * @property string|null $contract_clause_reference
 * @property string|null $installed_firmware_version
 * @property string|null $notes
 */
final class SerializedEquipmentPassport extends BaseEnterpriseModel
{
    protected $table = 'esm_equipment_passports';

    protected function casts(): array
    {
        return [
            'delivery_date' => 'date',
            'warranty_start_date' => 'date',
            'warranty_expiry_date' => 'date',
            'contract_warranty_months' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'current_owner_customer_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * @return HasMany<EquipmentComponent, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(EquipmentComponent::class, 'equipment_passport_id');
    }

    /**
     * @return HasMany<EquipmentComponent, $this>
     */
    public function currentComponents(): HasMany
    {
        return $this->components()->whereNull('removed_at');
    }

    /**
     * @return HasMany<WarrantyExtension, $this>
     */
    public function extensions(): HasMany
    {
        return $this->hasMany(WarrantyExtension::class, 'equipment_passport_id');
    }

    /**
     * @return HasMany<DepotRepairRmaOrder, $this>
     */
    public function rmaOrders(): HasMany
    {
        return $this->hasMany(DepotRepairRmaOrder::class, 'equipment_passport_id');
    }
}
