<?php

declare(strict_types=1);

namespace Modules\Observability\Connectors;

interface TlsCertificateInspector
{
    /**
     * Whole days until the certificate served by the host expires (negative when it already expired); null when it cannot be read.
     */
    public function daysRemaining(string $host, int $port = 443, float $timeoutSeconds = 5.0): ?int;
}
