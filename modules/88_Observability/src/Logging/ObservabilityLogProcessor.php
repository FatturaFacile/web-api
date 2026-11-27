<?php

declare(strict_types=1);

namespace Modules\Observability\Logging;

use App\Services\Tenancy\TenantContext;
use Modules\Observability\Privacy\PiiScrubber;
use Modules\Observability\Tracing\Tracer;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * Adds trace_id, span_id and tenant_id to every log record and passes message, context and extra through the PII scrubber.
 */
final class ObservabilityLogProcessor implements ProcessorInterface
{
    public function __construct(private readonly bool $flattenObjects = false) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $scrubber = app(PiiScrubber::class);
        $extra = $record->extra;

        try {
            $context = app(Tracer::class)->currentContext();

            if ($context !== null) {
                $extra['trace_id'] = $context->traceId;
                $extra['span_id'] = $context->spanId;
            }

            $tenantId = app(TenantContext::class)->getTenantId();

            if ($tenantId !== null) {
                $extra['tenant_id'] = $tenantId;
            }
        } catch (Throwable) {
            // Logging during a boot failure: the correlation ids are simply missing.
        }

        $extra['service'] = (string) config('observability.service.name', 'fatturafacile-api');

        return $record->with(
            message: $scrubber->scrubString($record->message),
            context: $scrubber->scrubArray($record->context, $this->flattenObjects),
            extra: $scrubber->scrubArray($extra, $this->flattenObjects),
        );
    }
}
