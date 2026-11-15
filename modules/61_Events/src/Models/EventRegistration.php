<?php

declare(strict_types=1);

namespace Modules\Events\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The registration of one attendee. A confirmed registration holds a place; a waitlisted one waits for a free place.
 * The QR token identifies the registration at check-in.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $event_id
 * @property string $ticket_type_id
 * @property string|null $contact_id
 * @property string $attendee_name
 * @property string|null $attendee_email
 * @property string $status
 * @property string $qr_token
 * @property string $price
 * @property Carbon $registered_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $checked_in_at
 * @property string|null $checked_in_by
 * @property Carbon|null $cancelled_at
 * @property string|null $notes
 */
final class EventRegistration extends BaseEnterpriseModel
{
    public const string Confirmed = 'confirmed';

    public const string Waitlisted = 'waitlisted';

    public const string Cancelled = 'cancelled';

    /** @var list<string> */
    public const array Statuses = [self::Confirmed, self::Waitlisted, self::Cancelled];

    protected $table = 'evt_registrations';

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'registered_at' => 'datetime',
            'paid_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ManagedEvent, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(ManagedEvent::class, 'event_id');
    }

    /**
     * @return BelongsTo<EventTicketType, $this>
     */
    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(EventTicketType::class, 'ticket_type_id');
    }
}
