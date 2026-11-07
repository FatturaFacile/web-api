<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Listeners;

use Modules\ServiceManagement\Events\HelpdeskTicketChanged;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Services\Helpdesk\CsatSurveyService;

/**
 * Sends the satisfaction survey when a ticket is resolved.
 */
final readonly class SendCsatSurveyListener
{
    public function __construct(private CsatSurveyService $surveys) {}

    public function handle(HelpdeskTicketChanged $event): void
    {
        if ($event->type !== HelpdeskTicketChanged::StatusChanged || ($event->payload['to'] ?? null) !== 'resolved') {
            return;
        }

        $ticket = Ticket::query()->where('tenant_id', $event->tenantId)->find($event->ticketId);

        if ($ticket !== null) {
            $this->surveys->sendFor($ticket);
        }
    }
}
