<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry of the history of a ticket: status change, assignment, merge, split, SLA event.
 */
final class TicketActivity extends BaseEnterpriseModel
{
    protected $table = 'esm_ticket_activities';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }
}
