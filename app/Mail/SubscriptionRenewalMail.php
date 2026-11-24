<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Modules\SubscriptionBilling\Models\Subscription;

/**
 * Renewal notice of a subscription: the end of its term and whether it renews by itself.
 */
class SubscriptionRenewalMail extends Mailable
{
    public function __construct(public readonly Subscription $subscription) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('emails.subscription_renewal.subject', [
            'number' => $this->subscription->subscription_number,
        ]));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.subscription-renewal', with: [
            'contact' => $this->subscription->customer,
            'subscriptionName' => $this->subscription->name ?? $this->subscription->plan?->name,
            'endDate' => $this->subscription->term_end_date?->format('Y-m-d'),
            'autoRenew' => (bool) $this->subscription->auto_renew,
        ]);
    }
}
