<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Infrastructure\Services;

use Illuminate\Support\Collection;
use Modules\DigitalPreservation\Domain\Exceptions\PreservationException;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatch;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatchItem;
use ZipArchive;

/**
 * Builds the archival package (PdA): the PdV.xml, the hash manifest and the RFC 3161 token in one bundle.
 */
final class PdaArchiveBuilder
{
    /**
     * @param  Collection<int, PreservationBatchItem>  $items
     */
    public function build(PreservationBatch $batch, Collection $items, string $pdvXml, string $timestampToken): string
    {
        $manifest = (string) json_encode([
            'package_type' => 'PdA', 'batch_number' => $batch->batch_number, 'merkle_root_sha256' => $batch->merkle_root_sha256,
            'previous_batch_sha256' => $batch->previous_batch_sha256, 'pdv_sha256' => $batch->pdv_sha256,
            'items' => $items->map(static fn (PreservationBatchItem $item): array => ['archive_path' => $item->archive_path, 'sha256' => $item->sha256_hash, 'size' => $item->file_size_bytes])->values()->all(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $path = tempnam(sys_get_temp_dir(), 'pda');
        $zip = new ZipArchive;

        if ($path === false || $zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new PreservationException('The archival package could not be created.');
        }

        $zip->addFromString('PdV.xml', $pdvXml);
        $zip->addFromString('manifest.json', $manifest);
        $zip->addFromString('timestamp.tsr', $timestampToken);
        $zip->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }
}
