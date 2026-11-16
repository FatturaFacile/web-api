<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\Contracts\MobilePushGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Forwards push messages to an HTTP relay that talks to FCM (HTTP v1) / APNs.
 */
final class RelayPushGateway implements MobilePushGateway
{
    public function send(string $provider, string $token, string $title, string $body, array $data = [], array $options = []): bool
    {
        $url = (string) config('mobile.push.relay_url');
        if ($url === '') {
            Log::warning('mobile.push.relay_url is not configured; push skipped.');

            return false;
        }

        $silent = (bool) ($options['silent'] ?? false);

        try {
            $response = Http::timeout(5)
                ->withToken((string) config('mobile.push.relay_token'))
                ->acceptJson()
                ->post($url, [
                    'provider' => $provider,
                    'token' => $token,
                    'notification' => $silent ? null : ['title' => $title, 'body' => $body],
                    'data' => array_map(static fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $data),
                    'category' => $options['category'] ?? null,
                    'ttl' => $options['ttl'] ?? 3600,
                ]);

            return $response->successful();
        } catch (Throwable $e) {
            Log::warning('mobile.push relay failed: '.$e->getMessage());

            return false;
        }
    }
}
