<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\ElectronicSignature\Contracts\SmsGateway;
use Modules\ElectronicSignature\Exceptions\SignatureException;

/**
 * SMS through any provider that accepts a JSON POST with a bearer token (`electronic_signature.sms`): the body is
 * `{"to": "+41...", "message": "...", "sender": "..."}`. A sandbox configuration never sends.
 */
final class HttpSmsGateway implements SmsGateway
{
    public function send(string $to, string $message, bool $sandbox): void
    {
        if ($sandbox) {
            Log::info('electronic-signature.sms.sandbox', ['to' => $this->mask($to)]);

            return;
        }

        $url = (string) config('electronic_signature.sms.url', '');

        if ($url === '') {
            throw new SignatureException('sms_unavailable', 503);
        }

        try {
            $response = Http::withToken((string) config('electronic_signature.sms.token', ''))
                ->timeout((int) config('electronic_signature.sms.timeout', 10))
                ->retry(2, 250, throw: false)
                ->acceptJson()
                ->post($url, ['to' => $to, 'message' => $message, 'sender' => (string) config('electronic_signature.sms.sender', 'FatturaFacile')]);
        } catch (ConnectionException) {
            throw new SignatureException('sms_unavailable', 503);
        }

        if ($response->failed()) {
            Log::warning('electronic-signature.sms.failed', ['status' => $response->status(), 'to' => $this->mask($to)]);

            throw new SignatureException('sms_unavailable', 503);
        }
    }

    private function mask(string $number): string
    {
        return strlen($number) > 6 ? substr($number, 0, 4).str_repeat('*', strlen($number) - 6).substr($number, -2) : '***';
    }
}
