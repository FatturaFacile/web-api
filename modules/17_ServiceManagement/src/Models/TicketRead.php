<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Up to when a colleague has read the conversation of a ticket.
 */
final class TicketRead extends BaseEnterpriseModel
{
    protected $table = 'esm_ticket_reads';

    protected function casts(): array
    {
        return [
            'last_read_at' => 'datetime',
        ];
    }
}
