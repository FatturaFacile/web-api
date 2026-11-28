<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Application\Commands;

use Modules\Beekeeping\Models\JarringRun;
use Modules\Beekeeping\Services\HoneyService;

final class RecordJarringRunHandler
{
    public function __construct(private readonly HoneyService $service) {}

    public function handle(RecordJarringRunCommand $command): JarringRun
    {
        return $this->service->recordJarring($command->batch, $command->data);
    }
}
