<?php

declare(strict_types=1);

namespace Modules\Observability\Application\Commands;

final readonly class RunConnectorProbesCommand
{
    public function __construct(public ?string $key = null) {}
}
