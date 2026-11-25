<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Commands;

/**
 * A roll cage read at a tunnel gate: the batch of UHF reads, the cage, the weighing and the direction.
 */
final readonly class ProcessRfidTunnelGateScanCommand
{
    /**
     * @param  list<array<string, mixed>>  $reads  Reader payload: epc, rssi (dBm), antenna_id, seen_at.
     */
    public function __construct(
        public string $tenantId,
        public ?string $userId,
        public string $gateAntennaId,
        public string $rollCageBarcode,
        public string $direction,
        public array $reads,
        public ?string $grossWeightKg = null,
        public ?string $rollCageTareWeightKg = null,
        public ?string $contactId = null,
        public ?string $externalScanId = null,
        public ?string $scannedAt = null,
        public bool $generateDdt = true,
    ) {}
}
