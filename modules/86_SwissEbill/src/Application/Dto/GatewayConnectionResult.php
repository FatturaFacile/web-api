<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Dto;

final readonly class GatewayConnectionResult
{
    public function __construct(
        public bool $reachable,
        public bool $authenticated,
        public int $latencyMs,
        public string $message,
        public ?string $environment = null,
    ) {}

    public function isConnected(): bool
    {
        return $this->reachable && $this->authenticated;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reachable' => $this->reachable,
            'authenticated' => $this->authenticated,
            'latency_ms' => $this->latencyMs,
            'message' => $this->message,
            'environment' => $this->environment,
        ];
    }
}
