<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Modules\SubscriptionBilling\Models\IndexationAdjustment;
use Modules\SubscriptionBilling\Models\Subscription;

/**
 * Customer communication of the annual inflation-index price adjustment, with the recalculation sheet.
 */
final class SubscriptionIndexationNoticeMail extends Mailable
{
    public function __construct(
        public readonly Subscription $subscription,
        public readonly IndexationAdjustment $adjustment,
        public readonly string $recipientName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('emails.subscription_indexation.subject', [
            'number' => $this->subscription->subscription_number,
        ]));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.subscription-indexation');
    }
}
