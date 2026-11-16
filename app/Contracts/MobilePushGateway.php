<?php

declare(strict_types=1);

namespace App\Contracts;

interface MobilePushGateway
{
    /**
     * Deliver a push message to a single device token.
     *
     * @param  array<string, mixed>  $data  Custom key/value payload
     * @param  array<string, mixed>  $options  category (interactive actions), silent, ttl
     */
    public function send(string $provider, string $token, string $title, string $body, array $data = [], array $options = []): bool;
}
