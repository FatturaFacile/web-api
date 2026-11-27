<?php

declare(strict_types=1);

namespace Modules\Observability\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Observability\Connectors\ConnectorProbe;
use Modules\Observability\Connectors\ConnectorStatusService;

/**
 * Probes one institutional connector and stores the result; one job per connector so a slow service never delays the others.
 */
final class ProbeConnectorJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly string $connectorKey) {}

    public function handle(ConnectorProbe $probe, ConnectorStatusService $statuses): void
    {
        $statuses->record($this->connectorKey, $probe->probe($this->connectorKey));
    }
}
