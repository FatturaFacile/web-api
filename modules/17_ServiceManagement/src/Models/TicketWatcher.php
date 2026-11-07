<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A colleague who follows a ticket and is notified of its changes.
 */
final class TicketWatcher extends BaseEnterpriseModel
{
    protected $table = 'esm_ticket_watchers';

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
