<?php

declare(strict_types=1);

namespace Modules\DataMigration\Application\Queries;

final readonly class GetMigrationDryRunReportQuery
{
    public function __construct(
        public string $jobId,
        public ?string $entityType = null,
        public bool $unresolvedOnly = false,
    ) {}
}
