<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Application\Handlers;

use Modules\DigitalPreservation\Application\Commands\GenerateDistributionPackageCommand;
use Modules\DigitalPreservation\Domain\Enums\AuditAction;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatch;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatchItem;
use Modules\DigitalPreservation\Infrastructure\Services\DistributionPackageBuilder;
use Modules\DigitalPreservation\Infrastructure\Services\PreservationAuditLogger;

/**
 * Exports the PdD of a batch or of a single document and logs the exhibition.
 */
final readonly class GenerateDistributionPackageHandler
{
    public function __construct(private DistributionPackageBuilder $builder, private PreservationAuditLogger $audit) {}

    /**
     * @return array{path: string, name: string}
     */
    public function handle(GenerateDistributionPackageCommand $command): array
    {
        if ($command->itemId !== null) {
            $item = PreservationBatchItem::findOrFail($command->itemId);
            $package = $this->builder->forItem($item);
            $this->audit->record(AuditAction::PddExported, null, $item, ['scope' => 'item', 'package' => $package['name']], $item->sha256_hash);

            return $package;
        }

        $batch = PreservationBatch::findOrFail($command->batchId);
        $package = $this->builder->forBatch($batch);
        $this->audit->record(AuditAction::PddExported, $batch, null, ['scope' => 'batch', 'package' => $package['name']], $batch->pdv_sha256);

        return $package;
    }
}
