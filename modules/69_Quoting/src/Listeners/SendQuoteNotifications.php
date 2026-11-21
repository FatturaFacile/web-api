<?php

declare(strict_types=1);

namespace Modules\Quoting\Listeners;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Quoting\Domain\Events\QuoteIssued;
use Modules\Quoting\Domain\Events\QuoteOtpRequested;

/**
 * Delivers the proposal link (the PIN travels in a separate message) and the one-time code of the signature.
 */
final class SendQuoteNotifications
{
    public function handleIssued(QuoteIssued $event): void
    {
        if ($event->customerEmail === null || $event->customerEmail === '') {
            return;
        }

        Mail::raw("Your proposal {$event->quoteNumber} is ready: {$event->portalUrl}", static function ($message) use ($event): void {
            $message->to($event->customerEmail)->subject("Proposal {$event->quoteNumber}");
        });

        if ($event->pin !== null) {
            Mail::raw("The PIN to open proposal {$event->quoteNumber} is {$event->pin}.", static function ($message) use ($event): void {
                $message->to($event->customerEmail)->subject("PIN for proposal {$event->quoteNumber}");
            });
        }
    }

    public function handleOtp(QuoteOtpRequested $event): void
    {
        if ($event->channel === 'email') {
            Mail::raw("Your signature code for {$event->quoteNumber} is {$event->code}. It expires shortly.", static function ($message) use ($event): void {
                $message->to($event->destination)->subject('Signature code');
            });

            return;
        }

        // No SMS gateway is configured at platform level yet: the code is never logged, only the fact that delivery is missing.
        Log::warning('Quote OTP requested by SMS but no SMS gateway is bound.', ['quote_id' => $event->quoteId]);
    }
}
