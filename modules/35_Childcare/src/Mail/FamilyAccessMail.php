<?php

declare(strict_types=1);

namespace Modules\Childcare\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The personal link of a guardian to the family portal.
 */
final class FamilyAccessMail extends Mailable
{
    use Queueable;

    public function __construct(public readonly string $link, public readonly string $token) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('childcare.mail.access_subject'));
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>'.e(__('childcare.mail.access_line')).'</p><p><a href="'.e($this->link).'">'.e($this->link).'</a></p>');
    }
}
