<?php

declare(strict_types=1);

namespace Modules\Observability\Application\Commands;

use Modules\Observability\Connectors\ConnectorCatalog;
use Modules\Observability\Connectors\ConnectorProbe;
use Modules\Observability\Connectors\ConnectorStatusService;

/**
 * Probes the connectors now (the "check now" button of the administrator) and returns their fresh state.
 */
final class RunConnectorProbesHandler
{
    public function __construct(
        private readonly ConnectorCatalog $catalog,
        private readonly ConnectorProbe $probe,
        private readonly ConnectorStatusService $statuses,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(RunConnectorProbesCommand $command): array
    {
        $keys = $command->key !== null ? [$command->key] : $this->catalog->keys();

        foreach ($keys as $key) {
            $this->statuses->record($key, $this->probe->probe($key));
        }

        return array_map(fn (string $key): array => $this->statuses->view($key, true), $keys);
    }
}
