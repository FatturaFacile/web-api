<?php

declare(strict_types=1);

namespace Modules\Observability\Console;

use Illuminate\Console\Command;
use Modules\Observability\Connectors\ConnectorCatalog;
use Modules\Observability\Jobs\ProbeConnectorJob;

/**
 * Scheduler entry of the synthetic probes: queues one probe job per institutional connector (or runs them inline with --sync).
 */
final class ProbeConnectorsCommand extends Command
{
    protected $signature = 'observability:probe-connectors {--key=* : Limit to these connectors} {--sync : Run the probes in this process}';

    protected $description = 'Probes the CH/IT institutional connectors (SDI, STS, Swissdec ELM, ZEFIX, UID, VIES, SNB, ECB, eBill) and updates their status';

    public function handle(ConnectorCatalog $catalog): int
    {
        $keys = array_values(array_intersect($catalog->keys(), $this->option('key') ?: $catalog->keys()));

        foreach ($keys as $key) {
            $this->option('sync') ? ProbeConnectorJob::dispatchSync($key) : ProbeConnectorJob::dispatch($key);
            $this->line($key.($this->option('sync') ? ' probed' : ' queued'));
        }

        return self::SUCCESS;
    }
}
