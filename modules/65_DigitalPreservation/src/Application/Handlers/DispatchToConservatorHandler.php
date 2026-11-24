<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Application\Handlers;

use Illuminate\Support\Facades\DB;
use Modules\DigitalPreservation\Application\Commands\DispatchToConservatorCommand;
use Modules\DigitalPreservation\Domain\Enums\AuditAction;
use Modules\DigitalPreservation\Domain\Enums\BatchStatus;
use Modules\DigitalPreservation\Domain\Events\PreservationPackageDispatched;
use Modules\DigitalPreservation\Domain\Exceptions\ConnectorException;
use Modules\DigitalPreservation\Domain\Exceptions\PreservationException;
use Modules\DigitalPreservation\Infrastructure\Connectors\PreservationConnectorManager;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatch;
use Modules\DigitalPreservation\Infrastructure\Services\PreservationAuditLogger;
use Modules\DigitalPreservation\Infrastructure\Services\PreservationConfigurationResolver;
use Modules\DigitalPreservation\Infrastructure\Services\PreservationVault;

/**
 * Hands a time-stamped batch to the connector of the tenant and stores the receipt (RdV). A refusal marks the batch
 * `failed`; a failed batch can be dispatched again.
 */
final readonly class DispatchToConservatorHandler
{
    public function __construct(
        private PreservationConnectorManager $connectors,
        private PreservationConfigurationResolver $configurations,
        private PreservationVault $vault,
        private PreservationAuditLogger $audit,
    ) {}

    /**
     * @throws PreservationException when the batch is not ready
     * @throws ConnectorException when the conservator refuses or cannot be reached
     */
    public function handle(DispatchToConservatorCommand $command): PreservationBatch
    {
        $batch = PreservationBatch::findOrFail($command->batchId);

        if (! in_array($batch->status, [BatchStatus::Timestamped, BatchStatus::Failed], true) || $batch->rfc3161_token_path === null) {
            throw new PreservationException('Only a sealed and time-stamped batch can be dispatched.');
        }

        $configuration = $this->configurations->current();
        $connector = $this->connectors->for($configuration->provider);
        $pdv = (string) $this->vault->get($batch->pdv_xml_path);
        $files = [];

        foreach ($batch->items()->get() as $item) {
            $files[$item->archive_path] = (string) $this->vault->get($item->archive_path);
        }

        try {
            $receipt = $connector->dispatch($batch, $pdv, $files, $configuration);
        } catch (ConnectorException $exception) {
            $batch->status = BatchStatus::Failed;
            $batch->save();
            $this->audit->record(AuditAction::Dispatched, $batch, null, ['provider' => $configuration->provider->value, 'outcome' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 300)], $batch->pdv_sha256);

            throw $exception;
        }

        $rdvPath = $this->vault->batchFolder($batch->tenant_id, $batch->batch_number).'/RdV.xml';

        if (! $this->vault->exists($rdvPath)) {
            $this->vault->put($rdvPath, $receipt->rdvXml);
        }

        DB::transaction(function () use ($batch, $receipt, $rdvPath): void {
            $batch->external_conservator_id = $receipt->externalId;
            $batch->rdv_receipt_path = $rdvPath;
            $batch->dispatched_at = now();
            $batch->status = $receipt->confirmed ? BatchStatus::Confirmed : BatchStatus::Dispatched;
            $batch->save();
        });

        $this->audit->record(AuditAction::Dispatched, $batch, null, ['provider' => $configuration->provider->value, 'external_id' => $receipt->externalId, 'outcome' => 'accepted'], $batch->pdv_sha256);
        $this->audit->record(AuditAction::RdvReceived, $batch, null, ['rdv_sha256' => hash('sha256', $receipt->rdvXml)], hash('sha256', $receipt->rdvXml));
        event(new PreservationPackageDispatched($batch->tenant_id, $batch->id, $configuration->provider->value, $receipt->externalId));

        return $batch->refresh();
    }
}
