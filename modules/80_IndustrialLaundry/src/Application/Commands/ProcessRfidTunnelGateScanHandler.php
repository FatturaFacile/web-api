<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Commands;

use Modules\IndustrialLaundry\Models\ScanSession;
use Modules\IndustrialLaundry\Services\RfidTunnelGateScanService;

final class ProcessRfidTunnelGateScanHandler
{
    public function __construct(private readonly RfidTunnelGateScanService $scans) {}

    public function handle(ProcessRfidTunnelGateScanCommand $command): ScanSession
    {
        return $this->scans->process($command);
    }
}
