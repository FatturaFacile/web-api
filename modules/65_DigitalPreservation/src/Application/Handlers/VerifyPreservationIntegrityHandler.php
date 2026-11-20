<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Application\Handlers;

use Modules\DigitalPreservation\Application\Commands\VerifyPreservationIntegrityCommand;
use Modules\DigitalPreservation\Domain\Enums\AuditAction;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatch;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatchItem;
use Modules\DigitalPreservation\Infrastructure\Services\PreservationAuditLogger;
use Modules\DigitalPreservation\Infrastructure\Services\PreservationIntegrityVerifier;

/**
 * Re-verifies a batch (files, Merkle root, chain, time stamp, audit chain) or one document.
 */
final readonly class VerifyPreservationIntegrityHandler
{
    public function __construct(private PreservationIntegrityVerifier $verifier, private PreservationAuditLogger $audit) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(VerifyPreservationIntegrityCommand $command): array
    {
        if ($command->itemId !== null) {
            $item = PreservationBatchItem::findOrFail($command->itemId);
            $result = $this->verifier->verifyItem($item);
            $this->audit->record($result['ok'] ? AuditAction::IntegrityVerified : AuditAction::IntegrityAlert, null, $item, ['scope' => 'item', 'ok' => $result['ok']], $item->sha256_hash);

            return $result;
        }

        return $this->verifier->verifyBatch(PreservationBatch::findOrFail($command->batchId));
    }
}
