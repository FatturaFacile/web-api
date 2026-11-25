<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Queries;

final readonly class DownloadArtifactQuery
{
    public function __construct(public string $dossierId, public string $artifactId) {}
}
