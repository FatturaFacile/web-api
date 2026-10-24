<?php

declare(strict_types=1);

namespace App\Actions\Subscriptions;

use App\Jobs\SendSubscriptionRenewalNotice;
use App\Models\Subscription;
use App\Services\Sales\DocumentEventRecorder;
use Carbon\CarbonInterface;

class NotifyUpcomingRenewals
{
    public function __construct(private readonly DocumentEventRecorder $events) {}

    /**
     * Log a one-off renewal notice for active subscriptions ending within their notice window.
     *
     * @return int number of subscriptions notified
     */
    public function execute(?CarbonInterface $asOf = null): int
    {
        $asOf = ($asOf ?? now())->copy()->startOfDay();
        $notified = 0;

        Subscription::query()
            ->where('status', 'active')
            ->whereNotNull('end_date')
            ->whereNull('renewal_notified_at')
            ->with('contact')
            ->get()
            ->each(function (Subscription $subscription) use ($asOf, &$notified): void {
                if ($subscription->end_date->gt($asOf->copy()->addDays($subscription->notify_days_before))) {
                    return;
                }

                $this->events->record($subscription, 'renewal_notice', [
                    'end_date' => $subscription->end_date->toDateString(),
                    'auto_renew' => $subscription->auto_renew,
                ]);
                $subscription->update(['renewal_notified_at' => now()]);

                if ($subscription->contact?->email !== null) {
                    SendSubscriptionRenewalNotice::dispatch($subscription->id);
                }
                $notified++;
            });

        return $notified;
    }
}
