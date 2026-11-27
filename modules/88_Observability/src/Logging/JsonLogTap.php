<?php

declare(strict_types=1);

namespace Modules\Observability\Logging;

use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Logger as MonologLogger;

/**
 * Tap of the `observability` channel: one JSON object per line (trace_id, span_id, tenant_id, scrubbed), objects and exceptions flattened.
 */
final class JsonLogTap
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if (! $monolog instanceof MonologLogger) {
            return;
        }

        $monolog->pushProcessor(new ObservabilityLogProcessor(true));

        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface) {
                $handler->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES, true));
            }
        }
    }
}
