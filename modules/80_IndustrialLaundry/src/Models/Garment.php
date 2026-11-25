<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\IndustrialLaundry\Domain\Enums\ArticleType;
use Modules\IndustrialLaundry\Domain\Enums\LifecycleState;

/**
 * A textile garment tagged with an RFID UHF transponder (TextileGarmentRfidItem).
 *
 * @property string $epc_uid_rfid_hex Normalised upper-case hexadecimal EPC of the tag.
 * @property string|null $thermal_barcode
 * @property ArticleType $article_type
 * @property string|null $product_id
 * @property string|null $description
 * @property string|null $assigned_customer_id
 * @property string|null $assigned_wearer_employee_name
 * @property string|null $assigned_locker_code
 * @property int $wash_cycles_count
 * @property int|null $max_certified_wash_cycles_limit
 * @property string|null $ppe_certification_standard
 * @property int|null $ppe_category
 * @property LifecycleState $current_lifecycle_state
 * @property bool $is_reissue_blocked
 * @property Carbon|null $last_scanned_at
 * @property Carbon|null $last_delivered_at
 * @property Carbon|null $last_soiled_at
 * @property Carbon|null $condemned_at
 * @property Carbon|null $unreturned_billed_at
 * @property string|null $mending_or_condemn_reason
 */
final class Garment extends BaseEnterpriseModel
{
    protected $table = 'ilr_garments';

    protected function casts(): array
    {
        return [
            'article_type' => ArticleType::class,
            'current_lifecycle_state' => LifecycleState::class,
            'wash_cycles_count' => 'integer',
            'max_certified_wash_cycles_limit' => 'integer',
            'ppe_category' => 'integer',
            'is_reissue_blocked' => 'boolean',
            'last_scanned_at' => 'datetime',
            'last_delivered_at' => 'datetime',
            'last_soiled_at' => 'datetime',
            'condemned_at' => 'datetime',
            'unreturned_billed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'assigned_customer_id');
    }

    /**
     * @return HasMany<GarmentEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(GarmentEvent::class, 'garment_id');
    }

    public function hasWashLimit(): bool
    {
        return $this->max_certified_wash_cycles_limit !== null && $this->max_certified_wash_cycles_limit > 0;
    }

    public function remainingWashes(): ?int
    {
        return $this->hasWashLimit() ? max(0, (int) $this->max_certified_wash_cycles_limit - $this->wash_cycles_count) : null;
    }
}
