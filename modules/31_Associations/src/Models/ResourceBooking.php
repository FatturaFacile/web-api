<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A period of a resource. The database refuses two confirmed bookings of a resource that overlap.
 */
final class ResourceBooking extends AssociationRecord
{
    public const string Confirmed = 'confirmed';

    public const string Cancelled = 'cancelled';

    protected $table = 'assoc_resource_bookings';

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(BookableResource::class, 'resource_id');
    }
}
