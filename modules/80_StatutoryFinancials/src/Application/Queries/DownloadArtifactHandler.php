<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Queries;

use Modules\StatutoryFinancials\Models\Artifact;
use Modules\StatutoryFinancials\Services\ArtifactStore;

final class DownloadArtifactHandler
{
    public function __construct(private readonly ArtifactStore $artifacts) {}

    /**
     * @return array{artifact: Artifact, contents: string}
     */
    public function handle(DownloadArtifactQuery $query): array
    {
        $artifact = Artifact::query()->where('dossier_id', $query->dossierId)->findOrFail($query->artifactId);

        return ['artifact' => $artifact, 'contents' => $this->artifacts->contents($artifact)];
    }
}
