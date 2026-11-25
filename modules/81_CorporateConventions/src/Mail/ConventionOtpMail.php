<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The one-time code that proves the employee owns a work e-mail address of the partner.
 */
final class ConventionOtpMail extends Mailable
{
    public function __construct(
        public readonly string $code,
        public readonly string $conventionName,
        public readonly int $ttlMinutes,
        string $locale,
    ) {
        $this->locale($locale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('corporate_conventions.otp.mail_subject', ['convention' => $this->conventionName], $this->locale));
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>'.e(__('corporate_conventions.otp.mail_body', [
            'code' => $this->code,
            'convention' => $this->conventionName,
            'minutes' => $this->ttlMinutes,
        ], $this->locale)).'</p>');
    }
}
