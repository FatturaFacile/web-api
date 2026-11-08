<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\SubscriptionRenewalMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Modules\SubscriptionBilling\Models\Subscription;

/**
 * Mails the renewal notice of a subscription to its customer, once (no retry, so no duplicate mail).
 */
class SendSubscriptionRenewalNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $subscriptionId)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $subscription = Subscription::withoutGlobalScopes()
            ->with(['customer' => fn ($query) => $query->withoutGlobalScopes()])
            ->find($this->subscriptionId);

        if ($subscription?->customer?->email === null) {
            return;
        }

        Mail::to($subscription->customer->email)->send(new SubscriptionRenewalMail($subscription));
    }
}
