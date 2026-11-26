<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Domain\ValueObjects;

use Illuminate\Http\Request;

/**
 * Where the request came from. The IP address is truncated by policy before it is kept (IPv4 /24, IPv6 /48) and, with the
 * user agent, stays outside the hash so that it can be purged on its retention schedule without breaking the chain.
 */
final readonly class ClientContext
{
    public const array CHANNELS = ['web', 'desktop', 'mobile', 'api'];

    public function __construct(
        public string $channel = 'web',
        public ?string $requestId = null,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
    ) {}

    public static function fromRequest(?Request $request): self
    {
        if ($request === null || (app()->runningInConsole() && $request->ip() === null)) {
            return new self('api');
        }

        $header = strtolower((string) $request->header('X-Client-Channel', ''));
        $channel = in_array($header, self::CHANNELS, true) ? $header : ($request->attributes->has('api_key_scopes') ? 'api' : 'web');
        $requestId = (string) ($request->header('X-Request-Id') ?? $request->attributes->get('request_id') ?? '');

        return new self(
            $channel,
            $requestId !== '' ? substr($requestId, 0, 64) : null,
            self::truncateIp($request->ip()),
            self::sanitizeUserAgent($request->userAgent()),
        );
    }

    public static function truncateIp(?string $ip): ?string
    {
        if ($ip === null || trim($ip) === '') {
            return null;
        }

        $packed = @inet_pton(trim($ip));

        if ($packed === false) {
            return null;
        }

        $ipv4Bits = max(8, min(32, (int) config('audit_ledger.ip_truncation.ipv4_prefix', 24)));
        $ipv6Bits = max(16, min(128, (int) config('audit_ledger.ip_truncation.ipv6_prefix', 48)));
        $bits = strlen($packed) === 4 ? $ipv4Bits : $ipv6Bits;
        $mask = '';

        for ($i = 0; $i < strlen($packed); $i++) {
            $keep = max(0, min(8, $bits - $i * 8));
            $mask .= chr((0xFF << (8 - $keep)) & 0xFF);
        }

        $masked = inet_ntop($packed & $mask);

        return $masked === false ? null : $masked;
    }

    public static function sanitizeUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return null;
        }

        return substr((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', trim($userAgent)), 0, 255);
    }

    /**
     * The part of the context covered by the hash.
     *
     * @return array{channel: string, request_id: string|null}
     */
    public function hashedPart(): array
    {
        return ['channel' => $this->channel, 'request_id' => $this->requestId];
    }
}
