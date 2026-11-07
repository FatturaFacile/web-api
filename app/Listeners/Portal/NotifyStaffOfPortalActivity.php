<?php

declare(strict_types=1);

namespace App\Listeners\Portal;

use App\Events\Portal\QuoteApprovedByCustomer;
use App\Events\Portal\QuoteRejectedByCustomer;
use App\Events\Portal\TicketRepliedByCustomer;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\PortalCustomerActivityNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the responsible staff what a customer did in the portal. The quote goes to whoever created it and the ticket to
 * its assignee; without one (or when that user left the company) the owners and administrators of the company are told.
 */
class NotifyStaffOfPortalActivity
{
    public function handleQuoteApproved(QuoteApprovedByCustomer $event): void
    {
        $quote = $event->quote;

        $this->notify(
            (string) $quote->tenant_id,
            $quote->getAttribute('created_by'),
            new PortalCustomerActivityNotification(
                activity: PortalCustomerActivityNotification::QuoteApproved,
                documentNumber: (string) $quote->quote_number,
                customerName: $event->signerName,
                context: ['quote_id' => (string) $quote->id, 'notes' => $event->notes],
            )
        );
    }

    public function handleQuoteRejected(QuoteRejectedByCustomer $event): void
    {
        $quote = $event->quote;

        $this->notify(
            (string) $quote->tenant_id,
            $quote->getAttribute('created_by'),
            new PortalCustomerActivityNotification(
                activity: PortalCustomerActivityNotification::QuoteRejected,
                documentNumber: (string) $quote->quote_number,
                customerName: $this->customerName($event->token->contact),
                reason: $event->reason,
                context: ['quote_id' => (string) $quote->id],
            )
        );
    }

    public function handleTicketReplied(TicketRepliedByCustomer $event): void
    {
        $ticket = $event->ticket;

        $this->notify(
            (string) $ticket->tenant_id,
            $ticket->assigned_to_user_id,
            new PortalCustomerActivityNotification(
                activity: PortalCustomerActivityNotification::TicketReplied,
                documentNumber: (string) ($ticket->subject ?? $ticket->id),
                customerName: (string) ($event->message->author_name ?? $this->customerName($event->token->contact)),
                context: ['ticket_id' => (string) $ticket->id, 'message_id' => (string) $event->message->id],
            )
        );
    }

    private function notify(string $tenantId, mixed $responsibleUserId, PortalCustomerActivityNotification $notification): void
    {
        $recipients = $this->recipients($tenantId, is_string($responsibleUserId) ? $responsibleUserId : null);

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, $notification);
        }
    }

    /**
     * @return Collection<int, User>
     */
    private function recipients(string $tenantId, ?string $responsibleUserId): Collection
    {
        $tenant = Tenant::query()->find($tenantId);
        if ($tenant === null) {
            return collect();
        }

        if ($responsibleUserId !== null) {
            $responsible = $tenant->users()->whereKey($responsibleUserId)->first();
            if ($responsible !== null) {
                return collect([$responsible]);
            }
        }

        return $tenant->users()->wherePivotIn('role', ['owner', 'admin'])->get();
    }

    private function customerName(mixed $contact): string
    {
        if ($contact === null) {
            return 'Customer';
        }

        $name = $contact->company_name ?: trim(($contact->first_name ?? '').' '.($contact->last_name ?? ''));

        return $name !== '' ? $name : 'Customer';
    }
}
