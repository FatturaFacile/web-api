<?php

declare(strict_types=1);

namespace Modules\Observability\Application\Commands;

final readonly class SetConnectorMaintenanceCommand
{
    public function __construct(public string $key, public bool $maintenance, public ?string $message = null) {}
}
