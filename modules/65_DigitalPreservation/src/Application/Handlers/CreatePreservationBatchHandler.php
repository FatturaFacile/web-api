<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Application\Handlers;

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\DigitalPreservation\Application\Commands\CreatePreservationBatchCommand;
use Modules\DigitalPreservation\Domain\Enums\AuditAction;
use Modules\DigitalPreservation\Domain\Enums\BatchStatus;
use Modules\DigitalPreservation\Domain\Exceptions\PreservationException;
use Modules\DigitalPreservation\Domain\ValueObjects\RetentionPolicy;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatch;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatchItem;
use Modules\DigitalPreservation\Infrastructure\Services\PdfAConformanceService;
use Modules\DigitalPreservation\Infrastructure\Services\PreservationAuditLogger;
use Modules\DigitalPreservation\Infrastructure\Services\PreservationCandidateCollector;
use Modules\DigitalPreservation\Infrastructure\Services\PreservationConfigurationResolver;
use Modules\DigitalPreservation\Infrastructure\Services\PreservationVault;

/**
 * Collects the unpreserved documents of a category and period, converts non-archival files to PDF/A, copies every file
 * into the write-once vault and creates the draft batch with its items.
 */
final readonly class CreatePreservationBatchHandler
{
    public function __construct(
        private PreservationCandidateCollector $collector,
        private PdfAConformanceService $pdfa,
        private PreservationVault $vault,
        private PreservationAuditLogger $audit,
        private PreservationConfigurationResolver $configurations,
        private TenantContext $tenantContext,
    ) {}

    /**
     * @throws PreservationException when there is nothing to preserve in the period
     */
    public function handle(CreatePreservationBatchCommand $command): PreservationBatch
    {
        $tenantId = (string) $this->tenantContext->getTenantId();
        $configuration = $this->configurations->current();
        $candidates = $this->collector->collect($command->category, $command->periodFrom, $command->periodTo);

        if ($candidates->isEmpty()) {
            throw new PreservationException('There are no unpreserved documents in this category and period.');
        }

        $retention = new RetentionPolicy($configuration->default_retention_years);
        $until = $retention->lockUntil($command->periodTo);
        $batchId = (string) Str::uuid7();
        $number = $this->nextNumber();
        $folder = $this->vault->batchFolder($tenantId, $number).'/documents';
        $rows = [];
        $events = [];
        $size = 0;

        foreach ($candidates->values() as $index => $candidate) {
            $archival = $this->pdfa->ensureArchival($candidate->content, $candidate->mimeType, $candidate->fileName);
            $itemId = (string) Str::uuid7();
            $archived = $itemId.'_'.preg_replace('/[^A-Za-z0-9._-]+/', '_', $archival['filename']);
            $path = $folder.'/'.$archived;
            $this->vault->put($path, $archival['content']);
            $size += strlen($archival['content']);

            $metadata = $candidate->metadata + ['digital_native' => true];
            $metadata['extension'] = pathinfo($archived, PATHINFO_EXTENSION);

            if ($archival['converted']) {
                $metadata['converted_from_mime'] = $candidate->mimeType;
                $metadata['original_sha256'] = hash('sha256', $candidate->content);
                $metadata['digital_native'] = false;
                $events[$itemId] = AuditAction::PdfaConverted;
            }

            $rows[] = [
                'id' => $itemId, 'tenant_id' => $tenantId, 'preservation_batch_id' => $batchId, 'sequence' => $index + 1,
                'preservable_type' => $candidate->preservableType, 'preservable_id' => $candidate->preservableId, 'document_uuid' => (string) Str::uuid(),
                'document_type' => $candidate->documentType, 'document_number' => $candidate->documentNumber, 'document_date' => $candidate->documentDate,
                'counterparty_name' => $candidate->counterpartyName, 'counterparty_tax_id' => $candidate->counterpartyTaxId,
                'original_filename' => $candidate->fileName, 'archived_filename' => $archived, 'archive_path' => $path, 'mime_type' => $archival['mime'],
                'is_pdfa_compliant' => $archival['pdfa'], 'pdfa_conformance_level' => $archival['level'], 'file_size_bytes' => strlen($archival['content']),
                'sha256_hash' => hash('sha256', $archival['content']), 'metadata_json' => $metadata, 'retention_until' => $until->toDateString(),
            ];
        }

        $batch = DB::transaction(function () use ($command, $configuration, $batchId, $number, $until, $size, $rows, $tenantId): PreservationBatch {
            $batch = new PreservationBatch([
                'batch_number' => $number, 'jurisdiction' => $command->jurisdiction ?? $configuration->jurisdiction, 'status' => BatchStatus::Draft,
                'document_category' => $command->category, 'period_from' => $command->periodFrom->toDateString(), 'period_to' => $command->periodTo->toDateString(),
                'item_count' => count($rows), 'total_size_bytes' => $size, 'retention_until' => $until->toDateString(),
            ]);
            $batch->id = $batchId;
            $batch->tenant_id = $tenantId;
            $batch->save();

            foreach ($rows as $row) {
                $item = new PreservationBatchItem($row);
                $item->id = $row['id'];
                $item->tenant_id = $tenantId;
                $item->save();
            }

            return $batch;
        });

        foreach ($rows as $row) {
            $item = PreservationBatchItem::find($row['id']);

            if (isset($events[$row['id']])) {
                $this->audit->record(AuditAction::PdfaConverted, $batch, $item, ['level' => $row['pdfa_conformance_level']], $row['sha256_hash']);
            }

            $this->audit->record(AuditAction::Ingested, $batch, $item, ['document_type' => $row['document_type'], 'file' => $row['archived_filename']], $row['sha256_hash']);
        }

        return $batch->refresh();
    }

    private function nextNumber(): string
    {
        $year = now()->format('Y');
        $count = PreservationBatch::where('batch_number', 'like', $year.'-%')->count();

        do {
            $count++;
            $number = sprintf('%s-%06d', $year, $count);
        } while (PreservationBatch::where('batch_number', $number)->exists());

        return $number;
    }
}
