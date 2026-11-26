<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Contracts\Sms\SmsDeliveryException;
use App\Contracts\Sms\SmsGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SMS through any provider that accepts a JSON POST with a bearer token (`sms.http`): the body is
 * `{"to": "+41...", "message": "...", "sender": "..."}`.
 */
final class HttpSmsGateway implements SmsGateway
{
    public function send(string $to, string $message): void
    {
        $url = (string) config('sms.http.url', '');

        if ($url === '') {
            throw new SmsDeliveryException('The SMS gateway is not configured.');
        }

        try {
            $response = Http::withToken((string) config('sms.http.token', ''))
                ->timeout((int) config('sms.http.timeout', 10))
                ->retry(2, 250, throw: false)
                ->acceptJson()
                ->post($url, ['to' => $to, 'message' => $message, 'sender' => (string) config('sms.http.sender', 'FatturaFacile')]);
        } catch (ConnectionException) {
            throw new SmsDeliveryException('The SMS gateway is unreachable.');
        }

        if ($response->failed()) {
            Log::warning('sms.http.failed', ['status' => $response->status(), 'to' => PhoneNumber::mask($to)]);

            throw new SmsDeliveryException('The SMS gateway refused the message.');
        }
    }
}
