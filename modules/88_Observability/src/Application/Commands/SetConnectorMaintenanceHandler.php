<?php

declare(strict_types=1);

namespace Modules\Observability\Application\Commands;

use Modules\Observability\Connectors\ConnectorStatusService;

final class SetConnectorMaintenanceHandler
{
    public function __construct(private readonly ConnectorStatusService $statuses) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(SetConnectorMaintenanceCommand $command): array
    {
        $this->statuses->setMaintenance($command->key, $command->maintenance, $command->message);

        return $this->statuses->view($command->key, true);
    }
}
