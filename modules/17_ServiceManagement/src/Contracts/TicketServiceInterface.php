<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Contracts;

use Modules\ServiceManagement\DTOs\AssignTicketDTO;
use Modules\ServiceManagement\DTOs\CreateTicketDTO;
use Modules\ServiceManagement\DTOs\EscalateTicketDTO;
use Modules\ServiceManagement\DTOs\ResolveTicketDTO;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketTimeEntry;

interface TicketServiceInterface
{
    public function createTicket(CreateTicketDTO $dto, ?string $actorUserId = null): Ticket;

    public function assignTicket(AssignTicketDTO $dto, ?string $actorUserId = null): Ticket;

    public function escalateTicket(EscalateTicketDTO $dto, ?string $actorUserId = null): Ticket;

    public function resolveTicket(ResolveTicketDTO $dto, ?string $actorUserId = null): Ticket;

    public function closeTicket(string $ticketId, string $closedBy, ?string $actorUserId = null): Ticket;

    public function logTime(
        string $ticketId,
        float $hours,
        ?string $technicianId = null,
        ?string $workDescription = null,
        bool $isBillable = true,
        float $hourlyRate = 75.0,
        ?string $actorUserId = null
    ): TicketTimeEntry;
}
