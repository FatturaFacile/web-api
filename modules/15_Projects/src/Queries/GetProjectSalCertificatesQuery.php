<?php

declare(strict_types=1);

namespace Modules\Projects\Queries;

/**
 * The SAL certificates of a project, with the cumulative progress and retention figures (Mod 081).
 */
final readonly class GetProjectSalCertificatesQuery
{
    public function __construct(
        public string $projectId,
    ) {}
}
