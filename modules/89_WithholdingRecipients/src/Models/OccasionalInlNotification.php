<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Preventive communication to the labour inspectorate (INL) of an occasional self-employed engagement (Art. 14 D.Lgs.
 * 81/2008). It must be sent before the work starts; without it the compensation cannot be settled.
 *
 * @property string $recipient_profile_id
 * @property string $status
 * @property Carbon $work_start_date
 * @property Carbon $work_end_date
 * @property string $activity_description
 * @property string|null $work_place
 * @property string $estimated_compensation
 * @property Carbon|null $sent_at
 * @property string|null $protocol_reference
 * @property array<string, mixed>|null $payload
 * @property string|null $xml
 */
final class OccasionalInlNotification extends BaseEnterpriseModel
{
    protected $table = 'fiscal_occasional_inl_notifications';

    protected function casts(): array
    {
        return [
            'work_start_date' => 'date',
            'work_end_date' => 'date',
            'estimated_compensation' => 'decimal:2',
            'sent_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<RecipientProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(RecipientProfile::class, 'recipient_profile_id');
    }

    /**
     * Valid for a service rendered on $serviceDate: sent, before the work started, and the service inside the declared period.
     */
    public function coversService(CarbonInterface $serviceDate): bool
    {
        $day = CarbonImmutable::instance($serviceDate)->startOfDay();

        return $this->status === 'sent'
            && $this->sent_at !== null
            && $this->sent_at->startOfDay()->lessThanOrEqualTo($this->work_start_date->startOfDay())
            && $day->between($this->work_start_date->startOfDay(), $this->work_end_date->endOfDay());
    }
}
