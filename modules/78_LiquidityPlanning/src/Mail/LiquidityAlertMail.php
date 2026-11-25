<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\LiquidityPlanning\Services\LiquidityTexts;

/**
 * The email of a liquidity early-warning alert: the title as subject and the message of the alert as body.
 */
final class LiquidityAlertMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly string $severity,
        public readonly string $language,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '['.LiquidityTexts::get('lpApi.severity.'.$this->severity, $this->language).'] '.$this->title);
    }

    public function content(): Content
    {
        $html = '<h2>'.e($this->title).'</h2><p>'.e($this->body).'</p><p><small>'.e(LiquidityTexts::get('lpApi.mail.footer', $this->language)).'</small></p>';

        return new Content(htmlString: $html);
    }
}
