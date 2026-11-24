<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services\Helpdesk\Email;

use Illuminate\Support\Facades\Mail;
use Modules\ServiceManagement\Enums\MessageAuthorType;
use Modules\ServiceManagement\Mail\HelpdeskTicketMail;
use Modules\ServiceManagement\Models\SupportMailbox;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketMessage;
use Modules\ServiceManagement\Services\Helpdesk\TicketActivityLog;
use Throwable;

/**
 * Sends helpdesk e-mail to the requester through the mailer of the installation, from the support mailbox the ticket
 * came in on. Every message gets its own Message-ID, stored on the ticket message, so the customer's answer threads back.
 */
final readonly class HelpdeskMailer
{
    public function __construct(private TicketActivityLog $activityLog) {}

    /**
     * Sends a public reply of an agent. Returns false when the ticket has no requester address or the mailer failed.
     */
    public function sendReply(Ticket $ticket, TicketMessage $message): bool
    {
        $recipient = $ticket->requesterEmail();

        if ($recipient === null) {
            return false;
        }

        $messageIdentifier = $message->email_message_id ?? $this->newMessageId($message->id);
        $thread = TicketMessage::query()
            ->where('ticket_id', $ticket->id)
            ->whereNotNull('email_message_id')
            ->where('id', '!=', $message->id)
            ->orderBy('created_at')
            ->get(['email_message_id', 'author_type']);

        $lastInbound = $thread->where('author_type', MessageAuthorType::Customer->value)->last()?->email_message_id;
        $references = array_values(array_slice($thread->pluck('email_message_id')->all(), -20));

        $sent = $this->send($ticket, $recipient, new HelpdeskTicketMail(
            mailSubject: $this->subjectFor($ticket, $lastInbound !== null),
            body: $message->body,
            messageIdentifier: $messageIdentifier,
            ticketNumber: $ticket->ticket_number,
            inReplyTo: $lastInbound,
            references: $references,
            fromAddress: $this->mailbox($ticket)?->email_address,
            fromName: $this->mailbox($ticket)?->name,
        ));

        if ($sent && $message->email_message_id === null) {
            $message->forceFill(['email_message_id' => $messageIdentifier])->save();
        }

        return $sent;
    }

    /**
     * Sends an automatic notice (acknowledgement, status change) to the requester.
     */
    public function sendNotice(Ticket $ticket, string $body): bool
    {
        $recipient = $ticket->requesterEmail();

        if ($recipient === null) {
            return false;
        }

        $lastInbound = TicketMessage::query()
            ->where('ticket_id', $ticket->id)
            ->where('author_type', MessageAuthorType::Customer->value)
            ->whereNotNull('email_message_id')
            ->orderByDesc('created_at')
            ->value('email_message_id');

        return $this->send($ticket, $recipient, new HelpdeskTicketMail(
            mailSubject: $this->subjectFor($ticket, $lastInbound !== null),
            body: $body,
            messageIdentifier: $this->newMessageId(null),
            ticketNumber: $ticket->ticket_number,
            inReplyTo: $lastInbound,
            references: $lastInbound === null ? [] : [$lastInbound],
            fromAddress: $this->mailbox($ticket)?->email_address,
            fromName: $this->mailbox($ticket)?->name,
            isAutomatic: true,
        ));
    }

    public function subjectFor(Ticket $ticket, bool $isReply): string
    {
        return ($isReply ? 'Re: ' : '')."[#{$ticket->ticket_number}] {$ticket->subject}";
    }

    private function send(Ticket $ticket, string $recipient, HelpdeskTicketMail $mail): bool
    {
        try {
            Mail::to($recipient)->send($mail);

            return true;
        } catch (Throwable $exception) {
            report($exception);
            $this->activityLog->record($ticket, 'email_failed', null, null, ['recipient' => $recipient, 'error' => mb_substr($exception->getMessage(), 0, 500)]);

            return false;
        }
    }

    private function mailbox(Ticket $ticket): ?SupportMailbox
    {
        $metadata = is_array($ticket->metadata) ? $ticket->metadata : [];
        $mailboxId = $metadata['mailbox_id'] ?? null;

        return is_string($mailboxId) ? SupportMailbox::query()->where('tenant_id', $ticket->tenant_id)->find($mailboxId) : null;
    }

    private function newMessageId(?string $seed): string
    {
        return ($seed ?? bin2hex(random_bytes(16))).'@'.config('helpdesk.message_id_domain', 'helpdesk.local');
    }
}
