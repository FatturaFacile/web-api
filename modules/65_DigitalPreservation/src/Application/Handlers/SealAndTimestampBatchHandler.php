<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Application\Handlers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\DigitalPreservation\Application\Commands\SealAndTimestampBatchCommand;
use Modules\DigitalPreservation\Domain\Enums\AuditAction;
use Modules\DigitalPreservation\Domain\Enums\BatchStatus;
use Modules\DigitalPreservation\Domain\Events\PreservationBatchSealed;
use Modules\DigitalPreservation\Domain\Exceptions\PreservationException;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatch;
use Modules\DigitalPreservation\Infrastructure\Services\AgidPdvXmlBuilder;
use Modules\DigitalPreservation\Infrastructure\Services\MerkleTreeHasher;
use Modules\DigitalPreservation\Infrastructure\Services\PdaArchiveBuilder;
use Modules\DigitalPreservation\Infrastructure\Services\PreservationAuditLogger;
use Modules\DigitalPreservation\Infrastructure\Services\PreservationConfigurationResolver;
use Modules\DigitalPreservation\Infrastructure\Services\PreservationVault;
use Modules\DigitalPreservation\Infrastructure\Services\Rfc3161TimestampService;

/**
 * Seals a draft batch (PdV.xml, Merkle root, link to the previous batch) and time-stamps it (RFC 3161), then builds the
 * PdA. When the time-stamp authority fails, the batch stays sealed and the call can be repeated: only the time stamp is
 * retried.
 */
final readonly class SealAndTimestampBatchHandler
{
    public function __construct(
        private AgidPdvXmlBuilder $xml,
        private MerkleTreeHasher $merkle,
        private Rfc3161TimestampService $timestamps,
        private PdaArchiveBuilder $pda,
        private PreservationVault $vault,
        private PreservationAuditLogger $audit,
        private PreservationConfigurationResolver $configurations,
    ) {}

    /**
     * @throws PreservationException when the batch is empty, already time-stamped, or the authority fails
     */
    public function handle(SealAndTimestampBatchCommand $command): PreservationBatch
    {
        $batch = PreservationBatch::findOrFail($command->batchId);

        if ($batch->status === BatchStatus::Draft) {
            $batch = $this->seal($batch, $command->actorId);
        }

        if ($batch->status !== BatchStatus::Sealed) {
            throw new PreservationException('The batch is already sealed and time-stamped.');
        }

        return $this->timestamp($batch);
    }

    private function seal(PreservationBatch $batch, ?string $actorId): PreservationBatch
    {
        $items = $batch->items()->get();

        if ($items->isEmpty()) {
            throw new PreservationException('An empty batch cannot be sealed.');
        }

        $configuration = $this->configurations->current();
        $sealedAt = now()->startOfSecond();
        $previous = PreservationBatch::where('id', '!=', $batch->id)->whereNotNull('pdv_sha256')->orderByDesc('sealed_at')->orderByDesc('created_at')->first();

        $batch->merkle_root_sha256 = $this->merkle->root($items->pluck('sha256_hash')->all());
        $batch->previous_batch_sha256 = $previous?->pdv_sha256;
        $pdv = $this->xml->build($batch, $items, $configuration, $this->configurations->producer(), $sealedAt);
        $pdvHash = $this->merkle->leaf($pdv);
        $path = $this->vault->batchFolder($batch->tenant_id, $batch->batch_number).'/PdV.xml';

        if (! $this->vault->exists($path)) {
            $this->vault->put($path, $pdv);
        }

        DB::transaction(function () use ($batch, $path, $pdvHash, $sealedAt, $actorId): void {
            $batch->pdv_xml_path = $path;
            $batch->pdv_sha256 = $pdvHash;
            $batch->status = BatchStatus::Sealed;
            $batch->sealed_by = $actorId ?? auth()->id();
            $batch->sealed_at = $sealedAt;
            $batch->save();
        });

        $this->audit->record(AuditAction::PdvGenerated, $batch, null, ['items' => $items->count()], $pdvHash);
        $this->audit->record(AuditAction::Sealed, $batch, null, ['merkle_root' => $batch->merkle_root_sha256, 'previous' => $batch->previous_batch_sha256], $pdvHash);

        return $batch;
    }

    private function timestamp(PreservationBatch $batch): PreservationBatch
    {
        $configuration = $this->configurations->current();
        $stamp = $this->timestamps->stamp((string) $batch->pdv_sha256, $configuration->exists ? $configuration : null);
        $folder = $this->vault->batchFolder($batch->tenant_id, $batch->batch_number);
        $tokenPath = $folder.'/timestamp.tsr';

        if (! $this->vault->exists($tokenPath)) {
            $this->vault->put($tokenPath, $stamp['token']);
        }

        $token = (string) $this->vault->get($tokenPath);
        $pdv = (string) $this->vault->get($batch->pdv_xml_path);
        $archive = $this->pda->build($batch, $batch->items()->get(), $pdv, $token);
        $pdaPath = $folder.'/PdA.zip';

        if (! $this->vault->exists($pdaPath)) {
            $this->vault->put($pdaPath, $archive);
        }

        $pdaHash = $this->merkle->leaf((string) $this->vault->get($pdaPath));

        DB::transaction(function () use ($batch, $tokenPath, $stamp, $pdaPath, $pdaHash): void {
            $batch->rfc3161_token_path = $tokenPath;
            $batch->rfc3161_gen_time = Carbon::instance($stamp['gen_time']);
            $batch->rfc3161_provider = $stamp['provider'];
            $batch->rfc3161_qualified = $stamp['qualified'];
            $batch->pda_archive_path = $pdaPath;
            $batch->pda_sha256 = $pdaHash;
            $batch->status = BatchStatus::Timestamped;
            $batch->save();
        });

        $this->audit->record(AuditAction::Timestamped, $batch, null, ['provider' => $stamp['provider'], 'qualified' => $stamp['qualified'], 'pda_sha256' => $pdaHash], $batch->pdv_sha256);
        event(new PreservationBatchSealed($batch->tenant_id, $batch->id, (string) $batch->merkle_root_sha256));

        return $batch->refresh();
    }
}
