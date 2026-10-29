<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Actions;

use Modules\ServiceManagement\Contracts\TicketServiceInterface;
use Modules\ServiceManagement\DTOs\CreateTicketDTO;
use Modules\ServiceManagement\Models\Ticket;

final readonly class CreateTicketAction
{
    public function __construct(
        private TicketServiceInterface $ticketService
    ) {}

    public function execute(CreateTicketDTO $dto, ?string $actorUserId = null): Ticket
    {
        return $this->ticketService->createTicket($dto, $actorUserId);
    }
}
