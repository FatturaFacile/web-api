<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services\Helpdesk;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Modules\ServiceManagement\Enums\MessageAuthorType;
use Modules\ServiceManagement\Events\HelpdeskTicketChanged;
use Modules\ServiceManagement\Mail\HelpdeskTicketMail;
use Modules\ServiceManagement\Models\HelpdeskNotification;
use Modules\ServiceManagement\Models\HelpdeskNotificationPreference;
use Modules\ServiceManagement\Models\HelpdeskSetting;
use Modules\ServiceManagement\Models\ServiceTeam;
use Modules\ServiceManagement\Models\ServiceTechnician;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketWatcher;
use Modules\ServiceManagement\Services\Helpdesk\Email\HelpdeskMailer;
use Throwable;

/**
 * Tells the people around a ticket what happened to it.
 *
 * Staff (the assigned agent, the watchers, and the team lead for SLA breaches and unassigned new tickets) get an in-app
 * notification and an e-mail, each as their preferences allow; the author of a change is not notified of it. The
 * requester gets an automatic e-mail when the ticket is opened and when its status changes; agent replies reach the
 * requester through the reply e-mail and resolution through the satisfaction survey when surveys are on.
 */
final readonly class HelpdeskNotifier
{
    public function __construct(private HelpdeskMailer $mailer) {}

    public function notify(HelpdeskTicketChanged $event): void
    {
        $ticket = Ticket::query()->where('tenant_id', $event->tenantId)->find($event->ticketId);

        if ($ticket === null) {
            return;
        }

        [$title, $body] = $this->describe($event, $ticket);

        foreach ($this->staffRecipients($event, $ticket) as $user) {
            $this->notifyUser($user, $event, $ticket, $title, $body);
        }

        $this->notifyRequester($event, $ticket);
    }

    /**
     * Preferences of a user for every event, with the defaults where nothing was saved.
     *
     * @return list<array{event: string, mail: bool, in_app: bool}>
     */
    public function preferencesOf(string $tenantId, string $userId): array
    {
        $saved = HelpdeskNotificationPreference::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->get()->keyBy('event');

        return array_map(static fn (string $type): array => [
            'event' => $type,
            'mail' => (bool) ($saved[$type]->mail ?? true),
            'in_app' => (bool) ($saved[$type]->in_app ?? true),
        ], HelpdeskTicketChanged::types());
    }

    /**
     * @return list<User>
     */
    private function staffRecipients(HelpdeskTicketChanged $event, Ticket $ticket): array
    {
        $userIds = TicketWatcher::query()->where('ticket_id', $ticket->id)->pluck('user_id')->all();

        if ($ticket->assigned_technician_id !== null) {
            $userIds[] = ServiceTechnician::query()->whereKey($ticket->assigned_technician_id)->value('user_id');
        }

        $needsLead = $event->type === HelpdeskTicketChanged::SlaBreached
            || ($event->type === HelpdeskTicketChanged::Created && $ticket->assigned_technician_id === null);

        if ($needsLead && $ticket->assigned_team_id !== null) {
            $userIds[] = ServiceTeam::query()->whereKey($ticket->assigned_team_id)->value('lead_user_id');
        }

        $userIds = array_values(array_unique(array_filter($userIds, fn (mixed $id): bool => is_string($id) && $id !== $event->actorUserId)));

        if ($userIds === []) {
            return [];
        }

        $members = DB::table('tenant_user')->where('tenant_id', $ticket->tenant_id)->whereIn('user_id', $userIds)->pluck('user_id')->all();

        return User::query()->whereIn('id', $members)->get()->all();
    }

    private function notifyUser(User $user, HelpdeskTicketChanged $event, Ticket $ticket, string $title, string $body): void
    {
        $preference = HelpdeskNotificationPreference::query()->where('tenant_id', $ticket->tenant_id)->where('user_id', $user->id)->where('event', $event->type)->first();

        if ($preference?->in_app ?? true) {
            HelpdeskNotification::query()->create([
                'tenant_id' => $ticket->tenant_id,
                'user_id' => $user->id,
                'ticket_id' => $ticket->id,
                'event' => $event->type,
                'title' => mb_substr($title, 0, 255),
                'body' => $body,
            ]);
        }

        if (($preference?->mail ?? true) && $user->email !== null) {
            try {
                Mail::to($user->email)->send(new HelpdeskTicketMail(
                    mailSubject: $title,
                    body: $body,
                    messageIdentifier: bin2hex(random_bytes(16)).'@'.config('helpdesk.message_id_domain', 'helpdesk.local'),
                    ticketNumber: $ticket->ticket_number,
                    isAutomatic: true,
                ));
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function notifyRequester(HelpdeskTicketChanged $event, Ticket $ticket): void
    {
        if ($event->type === HelpdeskTicketChanged::Created) {
            $this->mailer->sendNotice($ticket, "We received your request and opened ticket {$ticket->ticket_number}: {$ticket->subject}.\n\nReply to this e-mail to add information.");

            return;
        }

        if ($event->type !== HelpdeskTicketChanged::StatusChanged) {
            return;
        }

        $to = (string) ($event->payload['to'] ?? '');

        if ($to === 'resolved' && HelpdeskSetting::current($ticket->tenant_id)->csat_enabled) {
            return;
        }

        $this->mailer->sendNotice($ticket, match ($to) {
            'pending_customer' => "We need some information from you to go on with ticket {$ticket->ticket_number}. Please reply to this e-mail.",
            'resolved' => "Ticket {$ticket->ticket_number} has been resolved. Reply to this e-mail if the problem persists.",
            'closed' => "Ticket {$ticket->ticket_number} has been closed.",
            default => "The status of ticket {$ticket->ticket_number} is now: ".str_replace('_', ' ', $to).'.',
        });
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function describe(HelpdeskTicketChanged $event, Ticket $ticket): array
    {
        $reference = "[#{$ticket->ticket_number}] {$ticket->subject}";

        return match ($event->type) {
            HelpdeskTicketChanged::Created => ["New ticket {$reference}", "A new ticket was opened through {$ticket->source}."],
            HelpdeskTicketChanged::Replied => [
                ($event->payload['author_type'] ?? null) === MessageAuthorType::Customer->value ? "Customer reply on {$reference}" : "New message on {$reference}",
                ($event->payload['visibility'] ?? 'public') === 'internal' ? 'An internal note was added.' : 'A reply was added to the conversation.',
            ],
            HelpdeskTicketChanged::StatusChanged => ["Status changed on {$reference}", 'From '.($event->payload['from'] ?? '?').' to '.($event->payload['to'] ?? '?').'.'],
            HelpdeskTicketChanged::Assigned => ["Ticket assigned: {$reference}", 'The ticket was assigned.'],
            HelpdeskTicketChanged::SlaWarning => ["SLA warning on {$reference}", 'The '.($event->payload['target'] ?? 'SLA').' deadline is near: '.($event->payload['due_at'] ?? '').'.'],
            HelpdeskTicketChanged::SlaBreached => ["SLA breached on {$reference}", 'The '.($event->payload['target'] ?? 'SLA').' deadline has passed; the ticket was escalated to level '.($event->payload['escalation_level'] ?? '?').'.'],
            default => ["Update on {$reference}", ''],
        };
    }
}
