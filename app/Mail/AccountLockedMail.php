<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a user that the account was locked after repeated wrong passwords, for how long, and from which address and
 * device the attempts came. Encrypted on the queue: the payload carries the IP address and the device.
 */
class AccountLockedMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly int $minutes,
        public readonly CarbonInterface $lockedUntil,
        public readonly ?string $ipAddress,
        public readonly ?string $device,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('advanced_auth.mail.locked.subject', ['app' => config('app.name')]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.auth.account-locked');
    }
}
