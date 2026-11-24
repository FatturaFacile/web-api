<?php

declare(strict_types=1);

namespace Modules\Events\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A talk, workshop or slot of the programme of an event.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $event_id
 * @property string $title
 * @property string|null $speaker
 * @property string|null $room
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string|null $description
 */
final class EventSession extends BaseEnterpriseModel
{
    protected $table = 'evt_sessions';

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<ManagedEvent, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(ManagedEvent::class, 'event_id');
    }
}
