<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $locale
 */
class ShippingReasonTranslation extends Model
{
    use AllowsGlobalRecords, BelongsToTenant, HasFactory;

    protected $table = 'shipping_reason_translations';

    protected $fillable = [
        'tenant_id',
        'shipping_reason_id',
        'locale',
        'name',
        'description',
    ];

    /**
     * @return BelongsTo<ShippingReason, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ShippingReason::class, 'shipping_reason_id');
    }
}
