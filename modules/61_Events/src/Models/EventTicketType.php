<?php

declare(strict_types=1);

namespace Modules\Events\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of ticket with its price and the number of places it can sell (unlimited when empty).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $event_id
 * @property string $name
 * @property string $price
 * @property int|null $quantity
 * @property bool $is_active
 */
final class EventTicketType extends BaseEnterpriseModel
{
    protected $table = 'evt_ticket_types';

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'quantity' => 'integer', 'is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<ManagedEvent, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(ManagedEvent::class, 'event_id');
    }

    /**
     * @return HasMany<EventRegistration, $this>
     */
    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class, 'ticket_type_id');
    }
}
