<?php

declare(strict_types=1);

namespace Modules\Marketplace\Support;

/**
 * The outcome of a health check of an installation.
 */
final readonly class HealthCheckResult
{
    public const string Healthy = 'healthy';

    public const string Degraded = 'degraded';

    public const string Unhealthy = 'unhealthy';

    public function __construct(public string $status, public ?string $message = null, public ?int $latencyMs = null) {}

    public static function healthy(?int $latencyMs = null): self
    {
        return new self(self::Healthy, null, $latencyMs);
    }

    public function isUnhealthy(): bool
    {
        return $this->status === self::Unhealthy;
    }
}
