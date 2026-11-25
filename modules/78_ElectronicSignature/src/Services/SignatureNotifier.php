<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Services;

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\ElectronicSignature\Contracts\SmsGateway;
use Modules\ElectronicSignature\Mail\SignatureNotificationMail;
use Modules\ElectronicSignature\Models\SignatureEnvelope;
use Modules\ElectronicSignature\Models\SignatureRecipient;

/**
 * Sends the e-mails and SMS of the signature workflow. Every text comes from the seeded dictionary
 * (`signatures.mail.*`, `signatures.sms.*`) in the language of the envelope.
 */
final class SignatureNotifier
{
    public function __construct(private readonly SignatureTextResolver $texts, private readonly SmsGateway $sms) {}

    public function signingLink(string $token): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/sign/'.$token;
    }

    public function verificationLink(SignatureEnvelope $envelope): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/verify-signature?code='.rawurlencode($envelope->verification_code);
    }

    public function sendRequest(SignatureEnvelope $envelope, SignatureRecipient $recipient, string $token): void
    {
        $this->sendSigningMail('request', $envelope, $recipient, $token);
    }

    public function sendReminder(SignatureEnvelope $envelope, SignatureRecipient $recipient, string $token): void
    {
        $this->sendSigningMail('reminder', $envelope, $recipient, $token);
    }

    /**
     * Sends the signed document and the certificate of completion to every recipient (signers and copy recipients).
     */
    public function sendCompleted(SignatureEnvelope $envelope, SignatureRecipient $recipient, string $signedPdf, string $certificatePdf): void
    {
        $name = Str::slug($envelope->title) ?: 'document';
        $this->deliver(
            $recipient->email,
            $envelope,
            'completed',
            ['name' => $recipient->full_name, 'title' => $envelope->title, 'code' => $envelope->verification_code],
            $this->verificationLink($envelope),
            'signatures.mail.completed.action',
            [
                ['name' => $name.'-signed.pdf', 'content' => $signedPdf, 'mime' => 'application/pdf'],
                ['name' => $name.'-certificate.pdf', 'content' => $certificatePdf, 'mime' => 'application/pdf'],
            ],
        );
    }

    public function sendDeclined(SignatureEnvelope $envelope, SignatureRecipient $decliner): void
    {
        foreach ($this->owners($envelope) as $email) {
            $this->deliver($email, $envelope, 'declined', [
                'name' => $decliner->full_name,
                'title' => $envelope->title,
                'reason' => (string) $decliner->decline_reason,
            ]);
        }
    }

    public function sendExpired(SignatureEnvelope $envelope): void
    {
        foreach ($this->owners($envelope) as $email) {
            $this->deliver($email, $envelope, 'expired', ['title' => $envelope->title]);
        }
    }

    public function sendOtp(SignatureEnvelope $envelope, SignatureRecipient $recipient, string $code, bool $sandbox): void
    {
        if ($recipient->phone === null || $recipient->phone === '') {
            return;
        }

        $this->sms->send($recipient->phone, $this->texts->text('signatures.sms.otp', $envelope->locale, [
            'code' => $code,
            'minutes' => (int) config('electronic_signature.otp.ttl_minutes', 10),
        ], $envelope->tenant_id), $sandbox);
    }

    private function sendSigningMail(string $kind, SignatureEnvelope $envelope, SignatureRecipient $recipient, string $token): void
    {
        $this->deliver(
            $recipient->email,
            $envelope,
            $kind,
            [
                'name' => $recipient->full_name,
                'title' => $envelope->title,
                'sender' => $this->senderName($envelope),
                'message' => (string) $envelope->message,
                'expires' => $envelope->expires_at?->format('d.m.Y') ?? '',
                'level' => $envelope->required_level->value,
            ],
            $this->signingLink($token),
            "signatures.mail.{$kind}.action",
        );
    }

    /**
     * @param  array<string, scalar|null>  $replace
     * @param  list<array{name: string, content: string, mime: string}>  $files
     */
    private function deliver(string $to, SignatureEnvelope $envelope, string $kind, array $replace, ?string $url = null, ?string $actionKey = null, array $files = []): void
    {
        $locale = $envelope->locale;
        $tenantId = $envelope->tenant_id;
        $subject = $envelope->subject !== null && $envelope->subject !== '' && in_array($kind, ['request', 'reminder'], true)
            ? $envelope->subject
            : $this->texts->text("signatures.mail.{$kind}.subject", $locale, $replace, $tenantId);

        Mail::to($to)->send(new SignatureNotificationMail(
            $subject,
            $this->texts->text("signatures.mail.{$kind}.body", $locale, $replace, $tenantId),
            $url,
            $actionKey !== null ? $this->texts->text($actionKey, $locale, $replace, $tenantId) : null,
            $this->texts->text('signatures.mail.footer', $locale, [], $tenantId),
            $files,
        ));
    }

    /**
     * @return list<string> e-mail addresses of the user that created the envelope
     */
    private function owners(SignatureEnvelope $envelope): array
    {
        $user = $envelope->created_by !== null ? User::query()->find($envelope->created_by) : null;

        return $user !== null && $user->email !== '' ? [(string) $user->email] : [];
    }

    private function senderName(SignatureEnvelope $envelope): string
    {
        $user = $envelope->created_by !== null ? User::query()->find($envelope->created_by) : null;

        return (string) ($user->name ?? '');
    }
}
