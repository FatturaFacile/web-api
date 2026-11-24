<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Listeners;

use Modules\ServiceManagement\Events\HelpdeskTicketChanged;
use Modules\ServiceManagement\Services\Helpdesk\HelpdeskNotifier;
use Throwable;

/**
 * Notifies requester, assignee, watchers and team lead of a ticket change, once it is committed. A notification failure
 * never undoes or fails the change itself.
 */
final readonly class SendHelpdeskNotificationsListener
{
    public function __construct(private HelpdeskNotifier $notifier) {}

    public function handle(HelpdeskTicketChanged $event): void
    {
        try {
            $this->notifier->notify($event);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
