<?php

declare(strict_types=1);

namespace Modules\Observability\Connectors;

final readonly class ProbeResult
{
    public function __construct(
        public bool $ok,
        public ?int $httpStatus,
        public ?int $latencyMs,
        public ?int $tlsDaysRemaining = null,
        public ?bool $tlsValid = null,
        public ?string $error = null,
    ) {}
}
