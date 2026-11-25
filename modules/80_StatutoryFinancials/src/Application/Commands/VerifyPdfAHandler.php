<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Commands;

use Modules\StatutoryFinancials\Exceptions\StatutoryFinancialsException;
use Modules\StatutoryFinancials\Models\Artifact;
use Modules\StatutoryFinancials\Pdf\PdfAVerifier;
use Modules\StatutoryFinancials\Services\ArtifactStore;

final class VerifyPdfAHandler
{
    public function __construct(private readonly ArtifactStore $artifacts, private readonly PdfAVerifier $verifier) {}

    public function handle(VerifyPdfACommand $command): Artifact
    {
        $artifact = Artifact::query()->where('dossier_id', $command->dossierId)->findOrFail($command->artifactId);

        if ($artifact->pdfa_conformance === null) {
            throw new StatutoryFinancialsException('artifact_not_pdfa', 422);
        }

        $artifact->verification = $this->verifier->verify($this->artifacts->contents($artifact), $artifact->pdfa_conformance);
        $artifact->save();

        return $artifact;
    }
}
