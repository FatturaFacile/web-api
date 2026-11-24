<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Listeners;

use Modules\Platform\Services\TenantNotifier;
use Modules\ServiceManagement\Events\LoanerOverdueEvent;
use Throwable;

/**
 * Delivers the overdue loaner alert to the tenant's owners and administrators through the notification dispatcher
 * (preferences, quiet hours, digest and delivery register apply). A delivery failure never fails the scan.
 */
final readonly class NotifyLoanerOverdueListener
{
    public function __construct(private TenantNotifier $notifier) {}

    public function handle(LoanerOverdueEvent $event): void
    {
        try {
            $this->notifier->notify(
                $event->tenantId,
                'general',
                __('Loaner overdue: :serial', ['serial' => $event->loanerSerialNumber]),
                __('Loaner :serial is still out :days days after the repaired unit was returned', ['serial' => $event->loanerSerialNumber, 'days' => $event->daysOutstanding]),
                '/service/rma/'.$event->rmaOrderId,
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
