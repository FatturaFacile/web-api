<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\Contracts\MobilePushGateway;
use Illuminate\Support\Facades\Log;

final class LogPushGateway implements MobilePushGateway
{
    public function send(string $provider, string $token, string $title, string $body, array $data = [], array $options = []): bool
    {
        Log::info('mobile.push', [
            'provider' => $provider,
            'token_hash' => substr(hash('sha256', $token), 0, 12),
            'title' => $title,
            'category' => $options['category'] ?? null,
            'silent' => $options['silent'] ?? false,
        ]);

        return true;
    }
}
