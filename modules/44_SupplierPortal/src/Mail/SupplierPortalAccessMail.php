<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The personal link of a supplier to the supplier portal.
 */
final class SupplierPortalAccessMail extends Mailable
{
    use Queueable;

    public function __construct(public readonly string $link, public readonly string $token) {}

    /**
     * Subject of the email that carries the portal link.
     */
    public function envelope(): Envelope
    {
        return new Envelope(subject: __('supplier_portal.mail.access_subject'));
    }

    /**
     * Body of the email with the personal portal link.
     */
    public function content(): Content
    {
        return new Content(htmlString: '<p>'.e(__('supplier_portal.mail.access_line')).'</p><p><a href="'.e($this->link).'">'.e($this->link).'</a></p>');
    }
}
