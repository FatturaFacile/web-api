<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Modules\OliveMillAndGrainMill\Domain\Enums\Certification;
use Modules\OliveMillAndGrainMill\Domain\Enums\Commodity;
use Modules\OliveMillAndGrainMill\Domain\Enums\DeliveryStatus;
use Modules\OliveMillAndGrainMill\Domain\Enums\ProcessingMode;

/**
 * Crop delivered to the mill (olives or grain), on behalf of a farmer or for the mill's own account: lot, weighbridge reading and milling booking.
 *
 * @property int $campaign_year
 * @property string $lot_code
 * @property Carbon $delivered_at
 * @property ProcessingMode $processing_mode
 * @property Commodity $commodity
 * @property string|null $grain_type
 * @property string|null $farmer_contact_id
 * @property string|null $cultivar
 * @property string|null $origin_municipality
 * @property string|null $cadastral_reference
 * @property string|null $sian_origin_code
 * @property Certification $certification
 * @property string|null $gross_weight_kg
 * @property string $tare_kg
 * @property int $crate_count
 * @property string $crate_unit_tare_kg
 * @property string|null $net_weight_kg
 * @property string|null $weighbridge_ticket
 * @property array|null $scale_payload
 * @property Carbon|null $scale_read_at
 * @property Carbon|null $scheduled_at
 * @property int|null $estimated_duration_minutes
 * @property string|null $hopper_line
 * @property DeliveryStatus $status
 * @property Carbon|null $sms_reminder_sent_at
 * @property string|null $sms_recipient_phone
 * @property string|null $sms_status
 * @property string|null $notes
 * @property string|null $created_by
 */
final class OliveDelivery extends BaseEnterpriseModel
{
    protected $table = 'mill_deliveries';

    protected function casts(): array
    {
        return [
            'campaign_year' => 'integer',
            'delivered_at' => 'datetime',
            'processing_mode' => ProcessingMode::class,
            'commodity' => Commodity::class,
            'certification' => Certification::class,
            'gross_weight_kg' => 'decimal:3',
            'tare_kg' => 'decimal:3',
            'crate_count' => 'integer',
            'crate_unit_tare_kg' => 'decimal:3',
            'net_weight_kg' => 'decimal:3',
            'scale_payload' => 'array',
            'scale_read_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'estimated_duration_minutes' => 'integer',
            'status' => DeliveryStatus::class,
            'sms_reminder_sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'farmer_contact_id');
    }

    /**
     * @return HasOne<MillingCycle, $this>
     */
    public function cycle(): HasOne
    {
        return $this->hasOne(MillingCycle::class, 'delivery_id');
    }

    /**
     * @return HasOne<MillingSettlement, $this>
     */
    public function settlement(): HasOne
    {
        return $this->hasOne(MillingSettlement::class, 'delivery_id');
    }
}
