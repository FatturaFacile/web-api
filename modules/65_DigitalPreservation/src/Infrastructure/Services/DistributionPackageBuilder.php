<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Infrastructure\Services;

use Illuminate\Support\Collection;
use Modules\DigitalPreservation\Domain\Exceptions\PreservationException;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatch;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatchItem;
use Modules\DigitalPreservation\Infrastructure\Pdfa\SimplePdfWriter;
use ZipArchive;

/**
 * Builds the distribution package (PdD, "esibizione a norma") for a tax audit or a court: original files, PdV.xml, PdA
 * manifest, time-stamp token, conservator receipt, a conformity certificate and a machine-readable verification report.
 */
final readonly class DistributionPackageBuilder
{
    public function __construct(private PreservationVault $vault, private PreservationIntegrityVerifier $verifier) {}

    /**
     * Whole batch.
     *
     * @return array{path: string, name: string}
     */
    public function forBatch(PreservationBatch $batch): array
    {
        return $this->build($batch, $batch->items()->get(), $batch->batch_number);
    }

    /**
     * One document, with the evidence of its batch.
     *
     * @return array{path: string, name: string}
     */
    public function forItem(PreservationBatchItem $item): array
    {
        $batch = PreservationBatch::findOrFail($item->preservation_batch_id);

        return $this->build($batch, collect([$item]), $batch->batch_number.'_'.substr($item->document_uuid, 0, 8));
    }

    /**
     * @param  Collection<int, PreservationBatchItem>  $items
     * @return array{path: string, name: string}
     */
    private function build(PreservationBatch $batch, Collection $items, string $reference): array
    {
        if (! $batch->status->isFrozen()) {
            throw new PreservationException('Only a sealed batch can be exported as a distribution package.');
        }

        $report = $this->verifier->verifyBatch($batch, false);
        $name = 'PdD_'.$reference.'_'.now()->format('YmdHis').'.zip';
        $path = tempnam(sys_get_temp_dir(), 'pdd');
        $zip = new ZipArchive;

        if ($path === false || $zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new PreservationException('The distribution package could not be created.');
        }

        foreach ($items as $item) {
            $content = $this->vault->get($item->archive_path);

            if ($content !== null) {
                $zip->addFromString('documents/'.$item->archived_filename, $content);
            }
        }

        $zip->addFromString('PdV.xml', (string) $this->vault->get($batch->pdv_xml_path));
        $zip->addFromString('PdA_manifest.json', $this->manifest($batch));

        if (($token = $this->vault->get($batch->rfc3161_token_path)) !== null) {
            $zip->addFromString('timestamp.tsr', $token);
        }

        if (($rdv = $this->vault->get($batch->rdv_receipt_path)) !== null) {
            $zip->addFromString('RdV.xml', $rdv);
        }

        $zip->addFromString('verification_report.json', (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $zip->addFromString('CERTIFICATE_OF_CONFORMITY.pdf', $this->certificate($batch, $items, $report));
        $zip->close();

        return ['path' => $path, 'name' => $name];
    }

    private function manifest(PreservationBatch $batch): string
    {
        return (string) json_encode([
            'batch_number' => $batch->batch_number, 'jurisdiction' => $batch->jurisdiction->value, 'document_category' => $batch->document_category->value,
            'period_from' => $batch->period_from->toDateString(), 'period_to' => $batch->period_to->toDateString(), 'item_count' => $batch->item_count,
            'pdv_sha256' => $batch->pdv_sha256, 'pda_sha256' => $batch->pda_sha256, 'merkle_root_sha256' => $batch->merkle_root_sha256,
            'previous_batch_sha256' => $batch->previous_batch_sha256, 'retention_until' => $batch->retention_until?->toDateString(),
            'rfc3161_gen_time' => $batch->rfc3161_gen_time?->toIso8601String(), 'rfc3161_provider' => $batch->rfc3161_provider,
            'rfc3161_qualified' => $batch->rfc3161_qualified, 'external_conservator_id' => $batch->external_conservator_id,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  Collection<int, PreservationBatchItem>  $items
     * @param  array<string, mixed>  $report
     */
    private function certificate(PreservationBatch $batch, Collection $items, array $report): string
    {
        $lines = [
            'Certificate of conformity - digital preservation', '',
            'Batch: '.$batch->batch_number.'   Jurisdiction: '.$batch->jurisdiction->value,
            'Category: '.$batch->document_category->value.'   Period: '.$batch->period_from->toDateString().' / '.$batch->period_to->toDateString(),
            'Documents in this package: '.$items->count().' (batch total '.$batch->item_count.')',
            'Retention lock until: '.$batch->retention_until?->toDateString(),
            'PdV SHA-256: '.$batch->pdv_sha256,
            'Merkle root: '.$batch->merkle_root_sha256,
            'Previous batch: '.($batch->previous_batch_sha256 ?? 'none (first batch)'),
            'Time stamp: '.($batch->rfc3161_gen_time?->toIso8601String() ?? 'none').' ('.($batch->rfc3161_provider ?? '-').', '.($batch->rfc3161_qualified ? 'qualified' : 'not qualified').')',
            'Conservator reference: '.($batch->external_conservator_id ?? 'none'),
            '',
            'Verification at export: '.($report['ok'] ? 'ALL CHECKS PASSED' : 'FAILED - see verification_report.json'),
            'Files checked: '.$report['checked_files'].'   Failures: '.count($report['failures']),
            'Verified at: '.$report['verified_at'],
            '',
            'This certificate reports technical checks (SHA-256, Merkle root, hash chain, time stamp).',
            'It is not a legal attestation by an accredited conservator.',
        ];

        return SimplePdfWriter::build('Certificate of conformity '.$batch->batch_number, $lines, ['part' => 2, 'conformance' => 'B']);
    }
}
