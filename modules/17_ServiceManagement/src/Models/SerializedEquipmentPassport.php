<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use App\Models\Contact;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
 * @property CarbonImmutable|Carbon $delivery_date
 * @property string $warranty_policy_type
 * @property Carbon|null $warranty_start_date
 * @property Carbon $warranty_expiry_date
 * @property string $warranty_source
 * @property int|null $contract_warranty_months
 * @property string|null $contract_clause_reference
 * @property string|null $installed_firmware_version
 * @property string|null $notes
 * @property string $asset_status
 * @property Carbon|null $commissioning_date
 * @property string|null $installation_site
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
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
            'commissioning_date' => 'date',
        ];
    }

    public const STATUS_ACTIVE = 'active';

    public const STATUS_UNDER_REPAIR = 'under_repair';

    public const STATUS_DECOMMISSIONED = 'decommissioned';

    public function warrantyStatus(): string
    {
        return $this->warranty_expiry_date->toDateString() >= now()->toDateString() ? 'active' : 'expired';
    }

    /**
     * @return BelongsToMany<ServiceContract, $this>
     */
    public function maintenanceContracts(): BelongsToMany
    {
        return $this->belongsToMany(ServiceContract::class, 'esm_service_contract_assets', 'equipment_passport_id', 'service_contract_id')->withTimestamps();
    }

    /**
     * @return HasMany<WorkOrder, $this>
     */
    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'equipment_passport_id');
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
