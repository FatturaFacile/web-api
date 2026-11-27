<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Models;

use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Immutable audit trail of a transmission: every status change and every notable occurrence, appended once and never edited
 * or deleted.
 *
 * @property string $transmission_id
 * @property string $event_type
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string $source
 * @property string|null $gateway_event_id
 * @property Carbon $occurred_at
 * @property array<string, mixed>|null $details
 */
final class EbillTransmissionEvent extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'ebill_transmission_events';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('The eBill event trail is append-only.');
        });
        self::deleting(static function (): never {
            throw new LogicException('The eBill event trail is append-only.');
        });
    }

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'details' => 'array'];
    }

    /**
     * @return BelongsTo<EbillTransmission, $this>
     */
    public function transmission(): BelongsTo
    {
        return $this->belongsTo(EbillTransmission::class, 'transmission_id');
    }
}
