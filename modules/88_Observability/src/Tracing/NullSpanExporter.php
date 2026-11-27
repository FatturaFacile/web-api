<?php

declare(strict_types=1);

namespace Modules\Observability\Tracing;

/**
 * Keeps trace ids in logs and headers without sending anything (no collector configured).
 */
final class NullSpanExporter implements SpanExporter
{
    public function export(array $spans): void {}
}
