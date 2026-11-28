<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Harnesses of one size held by a ticket in the stock of its slot.
 *
 * @property string $ticket_id
 * @property string $size
 * @property int $quantity
 */
final class TicketLine extends BaseEnterpriseModel
{
    protected $table = 'adv_ticket_lines';

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }
}
