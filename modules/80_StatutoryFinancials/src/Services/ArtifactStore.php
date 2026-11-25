<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\StatutoryFinancials\Exceptions\StatutoryFinancialsException;
use Modules\StatutoryFinancials\Models\Artifact;
use Modules\StatutoryFinancials\Models\StatutoryDossier;

/**
 * Stores the generated XBRL instances and PDF/A documents on the configured disk and records them with their SHA-256 digest.
 * The file is written before the row, outside any database transaction.
 */
final class ArtifactStore
{
    /**
     * @param  array<string, mixed>|null  $verification
     */
    public function store(StatutoryDossier $dossier, string $kind, string $fileName, string $mimeType, string $contents, ?string $conformance = null, ?array $verification = null): Artifact
    {
        $disk = (string) config('statutory_financials.disk', 'local');
        $path = trim((string) config('statutory_financials.folder', 'statutory-financials'), '/').'/'.$dossier->tenant_id.'/'.$dossier->id.'/'.Str::uuid7().'-'.$fileName;

        Storage::disk($disk)->put($path, $contents);

        return Artifact::query()->create([
            'tenant_id' => $dossier->tenant_id,
            'dossier_id' => $dossier->id,
            'kind' => $kind,
            'disk' => $disk,
            'path' => $path,
            'file_name' => $fileName,
            'mime_type' => $mimeType,
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'pdfa_conformance' => $conformance,
            'verification' => $verification,
        ]);
    }

    public function contents(Artifact $artifact): string
    {
        $disk = Storage::disk($artifact->disk);

        if (! $disk->exists($artifact->path)) {
            throw new StatutoryFinancialsException('artifact_missing', 404);
        }

        return (string) $disk->get($artifact->path);
    }
}
