<?php

declare(strict_types=1);

namespace Modules\Observability\Tracing;

/**
 * Collects the exported spans in memory (tests and local debugging).
 */
final class InMemorySpanExporter implements SpanExporter
{
    /** @var list<Span> */
    public array $spans = [];

    public function export(array $spans): void
    {
        array_push($this->spans, ...$spans);
    }
}
