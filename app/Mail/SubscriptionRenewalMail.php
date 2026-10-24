<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Subscription;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

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
        return new Content(view: 'emails.subscription-renewal');
    }
}
