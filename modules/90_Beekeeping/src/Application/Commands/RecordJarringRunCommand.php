<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Application\Commands;

use Modules\Beekeeping\Models\ExtractionBatch;

final readonly class RecordJarringRunCommand
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public ExtractionBatch $batch, public array $data) {}
}
