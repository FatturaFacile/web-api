<?php

declare(strict_types=1);

namespace Modules\Observability\Tracing;

interface SpanExporter
{
    /**
     * @param  list<Span>  $spans  Finished and already scrubbed spans.
     */
    public function export(array $spans): void;
}
