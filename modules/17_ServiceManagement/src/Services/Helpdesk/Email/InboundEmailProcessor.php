<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services\Helpdesk\Email;

use App\Models\Contact;
use Illuminate\Support\Facades\DB;
use Modules\ServiceManagement\Contracts\TicketServiceInterface;
use Modules\ServiceManagement\DTOs\CreateTicketDTO;
use Modules\ServiceManagement\Models\SupportMailbox;
use Modules\ServiceManagement\Models\SupportMailboxMessage;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketMessage;
use Modules\ServiceManagement\Services\Helpdesk\HelpdeskThreadService;
use Modules\ServiceManagement\Services\Helpdesk\HelpdeskTicketService;
use Throwable;

/**
 * Turns one message of a support mailbox into a ticket or a reply. The tenant is the mailbox's: nothing in the message
 * chooses it. A reply is recognized by the ticket reference in the subject or by its In-Reply-To and References headers
 * pointing to a message of a ticket; anything else opens a new ticket.
 */
final readonly class InboundEmailProcessor
{
    /**
     * Ticket reference written in the subject of every outbound message, e.g. [#TCK-20270501-AB12CD].
     */
    public const string ReferencePattern = '/\[#?(TCK-\d{8}-[A-Z0-9]{6})\]/i';

    public function __construct(
        private InboundEmailParser $parser,
        private InboundLoopGuard $loopGuard,
        private TicketServiceInterface $ticketService,
        private HelpdeskThreadService $thread,
        private HelpdeskTicketService $tickets,
    ) {}

    public function process(SupportMailbox $mailbox, int $uid, string $raw): SupportMailboxMessage
    {
        $existing = SupportMailboxMessage::query()->where('mailbox_id', $mailbox->id)->where('uid', $uid)->first();

        if ($existing !== null) {
            return $existing;
        }

        $email = $this->parser->parse($raw);

        if ($email->messageId !== null && $this->alreadyImported($mailbox, $email->messageId)) {
            return $this->log($mailbox, $uid, $email, SupportMailboxMessage::Duplicate, null, 'Message-ID already imported');
        }

        $refusal = $this->loopGuard->check($email, $mailbox);

        if ($refusal !== null) {
            return $this->log($mailbox, $uid, $email, $refusal['outcome'], null, $refusal['reason']);
        }

        try {
            return DB::transaction(function () use ($mailbox, $uid, $email): SupportMailboxMessage {
                $ticket = $this->findThreadedTicket($mailbox, $email);

                if ($ticket !== null && ($ticket->statusEnum()->isActive() || $this->tickets->isWithinReopenWindow($ticket) || $ticket->merged_into_ticket_id !== null)) {
                    $message = $this->addReply($ticket, $email);

                    return $this->log($mailbox, $uid, $email, SupportMailboxMessage::Threaded, $message->ticket_id);
                }

                $created = $this->createTicket($mailbox, $email, $ticket);

                return $this->log($mailbox, $uid, $email, SupportMailboxMessage::Created, $created->id, $ticket === null ? null : "Follow-up of closed ticket {$ticket->ticket_number}");
            });
        } catch (Throwable $exception) {
            report($exception);

            return $this->log($mailbox, $uid, $email, SupportMailboxMessage::Failed, null, mb_substr($exception->getMessage(), 0, 1000));
        }
    }

    private function findThreadedTicket(SupportMailbox $mailbox, InboundEmail $email): ?Ticket
    {
        $number = preg_match(self::ReferencePattern, $email->subject, $match) === 1 ? strtoupper($match[1]) : null;

        if ($number !== null) {
            $ticket = Ticket::query()->where('tenant_id', $mailbox->tenant_id)->where('ticket_number', $number)->first();

            if ($ticket !== null) {
                return $ticket;
            }
        }

        $ids = array_values(array_unique(array_filter([$email->inReplyTo, ...array_reverse($email->references)])));

        if ($ids === []) {
            return null;
        }

        $ticketId = TicketMessage::query()
            ->where('tenant_id', $mailbox->tenant_id)
            ->whereIn('email_message_id', $ids)
            ->orderByDesc('created_at')
            ->value('ticket_id');

        return $ticketId === null ? null : Ticket::query()->where('tenant_id', $mailbox->tenant_id)->find($ticketId);
    }

    private function addReply(Ticket $ticket, InboundEmail $email): TicketMessage
    {
        $requester = $ticket->requesterEmail();
        $isKnownSender = $requester !== null && strtolower($requester) === $email->fromEmail;

        return $this->thread->addCustomerMessage(
            ticket: $ticket,
            body: $email->body !== '' ? $email->body : '(empty message)',
            contact: $this->contactFor($ticket->tenant_id, (string) $email->fromEmail),
            authorName: $email->fromName,
            authorEmail: $email->fromEmail,
            channel: 'email',
            attachments: $email->attachments,
            emailMessageId: $email->messageId,
            inReplyTo: $email->inReplyTo,
            metadata: $isKnownSender ? [] : ['unverified_sender' => true],
        );
    }

    private function createTicket(SupportMailbox $mailbox, InboundEmail $email, ?Ticket $followUpOf): Ticket
    {
        $contact = $this->contactFor($mailbox->tenant_id, (string) $email->fromEmail);
        $subject = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace(self::ReferencePattern, '', $email->subject)));

        $ticket = $this->ticketService->createTicket(new CreateTicketDTO(
            tenantId: $mailbox->tenant_id,
            companyId: $mailbox->tenant_id,
            title: mb_substr($subject !== '' ? $subject : '(no subject)', 0, 200),
            description: $email->body !== '' ? $email->body : '(empty message)',
            customerId: $contact?->id,
            contactEmail: $email->fromEmail,
            priority: $mailbox->default_priority ?: 'medium',
            serviceCategoryId: $mailbox->default_category_id,
            channel: 'email',
            serviceTeamId: $mailbox->default_team_id,
            metadata: array_filter([
                'mailbox_id' => $mailbox->id,
                'contact_name' => $email->fromName,
                'follow_up_of_ticket_id' => $followUpOf?->id,
            ]),
            status: 'new',
        ));

        $this->thread->addCustomerMessage(
            ticket: $ticket,
            body: $email->body !== '' ? $email->body : '(empty message)',
            contact: $contact,
            authorName: $email->fromName,
            authorEmail: $email->fromEmail,
            channel: 'email',
            attachments: $email->attachments,
            emailMessageId: $email->messageId,
            inReplyTo: $email->inReplyTo,
        );

        return $ticket;
    }

    private function contactFor(string $tenantId, string $email): ?Contact
    {
        return $email === '' ? null : Contact::query()->where('tenant_id', $tenantId)->whereRaw('lower(email) = ?', [$email])->first();
    }

    private function alreadyImported(SupportMailbox $mailbox, string $messageId): bool
    {
        return SupportMailboxMessage::query()->where('tenant_id', $mailbox->tenant_id)->where('message_id', $messageId)->whereIn('outcome', [SupportMailboxMessage::Created, SupportMailboxMessage::Threaded])->exists()
            || TicketMessage::query()->where('tenant_id', $mailbox->tenant_id)->where('email_message_id', $messageId)->exists();
    }

    private function log(SupportMailbox $mailbox, int $uid, InboundEmail $email, string $outcome, ?string $ticketId, ?string $reason = null): SupportMailboxMessage
    {
        return SupportMailboxMessage::query()->create([
            'tenant_id' => $mailbox->tenant_id,
            'mailbox_id' => $mailbox->id,
            'uid' => $uid,
            'message_id' => $email->messageId,
            'from_email' => $email->fromEmail === null ? null : mb_substr($email->fromEmail, 0, 180),
            'subject' => mb_substr($email->subject, 0, 255),
            'outcome' => $outcome,
            'ticket_id' => $ticketId,
            'reason' => $reason,
        ]);
    }
}
