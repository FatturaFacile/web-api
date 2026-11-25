<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A roll cage: the returnable container ("contenitore a rendere") linen travels in, weighed with its tare.
 *
 * @property string $barcode
 * @property string|null $label
 * @property string $tare_weight_kg
 * @property string|null $max_gross_weight_kg
 * @property bool $is_active
 * @property string|null $holder_contact_id Customer the cage is currently at, null while it is at the laundry.
 * @property Carbon|null $last_scanned_at
 */
final class RollCage extends BaseEnterpriseModel
{
    protected $table = 'ilr_roll_cages';

    protected function casts(): array
    {
        return [
            'tare_weight_kg' => 'decimal:3',
            'max_gross_weight_kg' => 'decimal:3',
            'is_active' => 'boolean',
            'last_scanned_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'holder_contact_id');
    }
}
