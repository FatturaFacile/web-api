<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Events;

/**
 * Honey was jarred under a new jar lot (the stock of jars, caps and labels was consumed).
 */
final readonly class JarringRunRecorded
{
    public function __construct(public string $jarringRunId, public string $extractionBatchId, public string $jarLotCode) {}
}
