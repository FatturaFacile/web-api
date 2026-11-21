<?php

declare(strict_types=1);

namespace Modules\Quoting\Infrastructure\Signature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Modules\Quoting\Domain\Contracts\ElectronicSignaturePort;
use Modules\Quoting\Domain\Events\QuoteOtpRequested;
use Modules\Quoting\Domain\Exceptions\QuoteAccessDeniedException;
use Modules\Quoting\Infrastructure\Models\CpqQuote;
use Modules\Quoting\Infrastructure\Models\CpqSignatureEnvelope;

/**
 * Level 1, simple electronic signature: a 6-digit one-time code by email or SMS, valid for a few minutes and limited in
 * attempts. The code is stored hashed; the plaintext leaves only through QuoteOtpRequested (the listener delivers it).
 */
final class NativeOtpSignatureAdapter implements ElectronicSignaturePort
{
    public function provider(): string
    {
        return 'native_otp';
    }

    public function initiate(CpqQuote $quote, CpqSignatureEnvelope $envelope): array
    {
        $channel = $envelope->otp_channel === 'sms' ? 'sms' : 'email';
        $destination = $channel === 'sms' ? $envelope->signer_phone : $envelope->signer_email;

        if ($destination === null || $destination === '') {
            throw new QuoteAccessDeniedException("The signer has no {$channel} destination for the one-time code.");
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $envelope->forceFill([
            'otp_hash' => Hash::make($code),
            'otp_expires_at' => Carbon::now()->addMinutes((int) config('quoting.otp_ttl_minutes', 10)),
            'otp_attempts' => 0,
            'otp_channel' => $channel,
        ])->save();

        event(new QuoteOtpRequested($quote->id, $channel, $destination, $code, $quote->quote_number));

        return ['otp_sent' => true, 'channel' => $channel, 'expires_at' => $envelope->otp_expires_at?->toIso8601String()];
    }

    public function verify(CpqSignatureEnvelope $envelope, array $proof): array
    {
        $code = (string) ($proof['otp'] ?? '');

        if ($envelope->otp_hash === null || $envelope->otp_expires_at === null || $envelope->otp_expires_at->isPast()) {
            throw new QuoteAccessDeniedException('The one-time code has expired: request a new one.');
        }

        if ($envelope->otp_attempts >= (int) config('quoting.otp_max_attempts', 5)) {
            throw new QuoteAccessDeniedException('Too many attempts: request a new one-time code.');
        }

        $envelope->forceFill(['otp_attempts' => $envelope->otp_attempts + 1])->save();

        if (preg_match('/^\d{6}$/', $code) !== 1 || ! Hash::check($code, $envelope->otp_hash)) {
            throw new QuoteAccessDeniedException('The one-time code is not valid.');
        }

        // Single use.
        $envelope->forceFill(['otp_hash' => null])->save();

        return ['method' => 'otp', 'channel' => $envelope->otp_channel, 'verified_at' => Carbon::now('UTC')->toIso8601String()];
    }
}
