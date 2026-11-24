<?php

declare(strict_types=1);

namespace Modules\Events\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An event the company organises (conference, training, open day) with its capacity and waiting list.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string|null $venue
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string $status
 * @property int|null $capacity
 * @property bool $waitlist_enabled
 * @property string $currency
 * @property string|null $notes
 */
final class ManagedEvent extends BaseEnterpriseModel
{
    public const string Draft = 'draft';

    public const string Published = 'published';

    public const string Closed = 'closed';

    public const string Cancelled = 'cancelled';

    /** @var list<string> */
    public const array Statuses = [self::Draft, self::Published, self::Closed, self::Cancelled];

    protected $table = 'evt_events';

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'capacity' => 'integer',
            'waitlist_enabled' => 'boolean',
        ];
    }

    /**
     * @return HasMany<EventSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(EventSession::class, 'event_id')->orderBy('starts_at');
    }

    /**
     * @return HasMany<EventTicketType, $this>
     */
    public function ticketTypes(): HasMany
    {
        return $this->hasMany(EventTicketType::class, 'event_id')->orderBy('price')->orderBy('name');
    }

    /**
     * @return HasMany<EventRegistration, $this>
     */
    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class, 'event_id')->orderBy('registered_at');
    }
}
