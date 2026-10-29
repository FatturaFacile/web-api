<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\SubscriptionRenewalMail;
use App\Models\Subscription;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class SendSubscriptionRenewalNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $subscriptionId)
    {
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        $subscription = Subscription::withoutGlobalScopes()
            ->with(['contact' => fn ($q) => $q->withoutGlobalScopes()])
            ->find($this->subscriptionId);

        if ($subscription?->contact?->email === null) {
            return;
        }

        Mail::to($subscription->contact->email)->send(new SubscriptionRenewalMail($subscription));
    }
}
