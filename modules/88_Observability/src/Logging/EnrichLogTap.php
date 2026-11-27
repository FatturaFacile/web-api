<?php

declare(strict_types=1);

namespace Modules\Observability\Logging;

use Illuminate\Log\Logger;
use Monolog\Logger as MonologLogger;

/**
 * Logging tap for the existing channels: correlation ids and PII scrubbing, the format stays as it is.
 */
final class EnrichLogTap
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if ($monolog instanceof MonologLogger) {
            $monolog->pushProcessor(new ObservabilityLogProcessor(false));
        }
    }
}
