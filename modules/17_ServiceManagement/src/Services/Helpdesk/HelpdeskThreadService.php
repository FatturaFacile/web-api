<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services\Helpdesk;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\ServiceManagement\Enums\MessageAuthorType;
use Modules\ServiceManagement\Enums\MessageVisibility;
use Modules\ServiceManagement\Enums\TicketStatus;
use Modules\ServiceManagement\Events\HelpdeskTicketChanged;
use Modules\ServiceManagement\Exceptions\HelpdeskRuleViolation;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketMessage;
use Modules\ServiceManagement\Models\TicketRead;

/**
 * The conversation of a ticket: staff replies and internal notes, customer replies from the portal and from e-mail,
 * the thread including the history of merged tickets, and the read state of each colleague.
 */
final readonly class HelpdeskThreadService
{
    public function __construct(
        private HelpdeskTicketService $tickets,
        private TicketMessageWriter $writer,
        private TicketActivityLog $activityLog,
    ) {}

    /**
     * A reply or an internal note of a colleague. The first public reply stamps the first response; a reply may move
     * the ticket to a new status in the same step.
     *
     * @param  list<UploadedFile|array{name: string, content: string, mime?: string}>  $attachments
     */
    public function addStaffMessage(Ticket $ticket, User $author, string $body, MessageVisibility $visibility, array $attachments = [], ?TicketStatus $statusAfter = null): TicketMessage
    {
        return DB::transaction(function () use ($ticket, $author, $body, $visibility, $attachments, $statusAfter): TicketMessage {
            $ticket = $this->tickets->lock($ticket->id);
            $this->tickets->assertNotMerged($ticket);

            $message = $this->writer->write($ticket, [
                'author_type' => MessageAuthorType::Staff->value,
                'author_user_id' => $author->id,
                'author_name' => $author->name,
                'author_email' => $author->email,
                'visibility' => $visibility->value,
                'channel' => 'web',
                'body' => $body,
            ], $attachments);

            if ($visibility === MessageVisibility::Public) {
                if ($ticket->first_responded_at === null) {
                    $ticket->first_responded_at = now();
                    $this->activityLog->record($ticket, 'first_response', null, null, ['message_id' => $message->id], $author->id);
                }

                if ($statusAfter === null && $ticket->statusEnum() === TicketStatus::New) {
                    $statusAfter = TicketStatus::Open;
                }

                $ticket->save();
            }

            if ($statusAfter !== null && $statusAfter !== $ticket->statusEnum()) {
                $this->tickets->applyStatus($ticket, $statusAfter, $author->id);
            }

            $this->markRead($ticket, $author);

            event(new HelpdeskTicketChanged($ticket->id, $ticket->tenant_id, HelpdeskTicketChanged::Replied, $author->id, [
                'message_id' => $message->id,
                'visibility' => $visibility->value,
                'author_type' => MessageAuthorType::Staff->value,
            ]));

            return $message;
        });
    }

    /**
     * A reply of the customer. A ticket merged into another receives it on the target. A ticket waiting for the
     * customer goes back to open; a resolved or closed one reopens within the reopen window and refuses it afterwards.
     *
     * @param  list<UploadedFile|array{name: string, content: string, mime?: string}>  $attachments
     * @param  array<string, mixed>  $metadata
     */
    public function addCustomerMessage(
        Ticket $ticket,
        string $body,
        ?Contact $contact = null,
        ?string $authorName = null,
        ?string $authorEmail = null,
        string $channel = 'portal',
        array $attachments = [],
        ?string $emailMessageId = null,
        ?string $inReplyTo = null,
        array $metadata = [],
    ): TicketMessage {
        return DB::transaction(function () use ($ticket, $body, $contact, $authorName, $authorEmail, $channel, $attachments, $emailMessageId, $inReplyTo, $metadata): TicketMessage {
            $ticket = $this->tickets->lock($ticket->id);

            for ($hops = 0; $ticket->merged_into_ticket_id !== null && $hops < 10; $hops++) {
                $ticket = $this->tickets->lock($ticket->merged_into_ticket_id);
            }

            $status = $ticket->statusEnum();

            if (! $status->isActive() && ! $this->tickets->isWithinReopenWindow($ticket)) {
                throw new HelpdeskRuleViolation('This ticket is closed and can no longer be reopened: please open a new request.');
            }

            $message = $this->writer->write($ticket, [
                'author_type' => MessageAuthorType::Customer->value,
                'author_contact_id' => $contact?->id,
                'author_name' => $authorName ?? ($contact === null ? null : $ticket->requesterName()),
                'author_email' => $authorEmail ?? $contact?->email,
                'visibility' => MessageVisibility::Public->value,
                'channel' => $channel,
                'body' => $body,
                'email_message_id' => $emailMessageId,
                'in_reply_to' => $inReplyTo,
                'metadata' => $metadata === [] ? null : $metadata,
            ], $attachments);

            if (in_array($status, [TicketStatus::PendingCustomer, TicketStatus::Resolved, TicketStatus::Closed], true)) {
                $this->tickets->applyStatus($ticket, TicketStatus::Open, null, 'Customer reply');
            }

            $ticket->customer_last_read_at = now();
            $ticket->save();

            event(new HelpdeskTicketChanged($ticket->id, $ticket->tenant_id, HelpdeskTicketChanged::Replied, null, [
                'message_id' => $message->id,
                'visibility' => MessageVisibility::Public->value,
                'author_type' => MessageAuthorType::Customer->value,
            ]));

            return $message;
        });
    }

    /**
     * Messages of the ticket and of the tickets merged into it, oldest first. Customers never see internal notes.
     *
     * @return Collection<int, TicketMessage>
     */
    public function thread(Ticket $ticket, bool $includeInternal): Collection
    {
        $ticketIds = [$ticket->id, ...Ticket::query()->where('merged_into_ticket_id', $ticket->id)->pluck('id')->all()];

        return TicketMessage::query()
            ->with('attachments')
            ->whereIn('ticket_id', $ticketIds)
            ->when(! $includeInternal, fn ($query) => $query->where('visibility', MessageVisibility::Public->value))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    public function markRead(Ticket $ticket, User $user): TicketRead
    {
        $read = TicketRead::query()->firstOrNew(['ticket_id' => $ticket->id, 'user_id' => $user->id]);
        $read->tenant_id = $ticket->tenant_id;
        $read->last_read_at = now();
        $read->save();

        return $read;
    }

    public function unreadCount(Ticket $ticket, User $user): int
    {
        $lastRead = TicketRead::query()->where('ticket_id', $ticket->id)->where('user_id', $user->id)->value('last_read_at');

        return TicketMessage::query()
            ->where('ticket_id', $ticket->id)
            ->where(fn ($query) => $query->whereNull('author_user_id')->orWhere('author_user_id', '!=', $user->id))
            ->when($lastRead !== null, fn ($query) => $query->where('created_at', '>', $lastRead))
            ->count();
    }
}
