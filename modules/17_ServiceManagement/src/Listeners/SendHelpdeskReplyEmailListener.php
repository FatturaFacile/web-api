<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Listeners;

use Modules\ServiceManagement\Enums\MessageAuthorType;
use Modules\ServiceManagement\Enums\MessageVisibility;
use Modules\ServiceManagement\Events\HelpdeskTicketChanged;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketMessage;
use Modules\ServiceManagement\Services\Helpdesk\Email\HelpdeskMailer;

/**
 * Sends the public replies of agents to the requester by e-mail, once the reply is committed.
 */
final readonly class SendHelpdeskReplyEmailListener
{
    public function __construct(private HelpdeskMailer $mailer) {}

    public function handle(HelpdeskTicketChanged $event): void
    {
        if ($event->type !== HelpdeskTicketChanged::Replied
            || ($event->payload['author_type'] ?? null) !== MessageAuthorType::Staff->value
            || ($event->payload['visibility'] ?? null) !== MessageVisibility::Public->value) {
            return;
        }

        $ticket = Ticket::query()->where('tenant_id', $event->tenantId)->find($event->ticketId);
        $message = TicketMessage::query()->where('tenant_id', $event->tenantId)->find($event->payload['message_id'] ?? null);

        if ($ticket !== null && $message !== null) {
            $this->mailer->sendReply($ticket, $message);
        }
    }
}
